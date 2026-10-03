<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Events\Auth\CredentialsRevoked;
use App\Models\RefreshToken;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Account\StaffAccountService;
use App\Services\AuditLogService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Registration, WhatsApp-OTP verification, login, and password reset.
 *
 * Registration always creates a PATIENT. Staff and therapist accounts are
 * created by management (StaffAccountService) and activated here with a
 * one-time code — never through registration.
 */
class AuthService
{
    private const UNVERIFIED_USER_TTL_HOURS = 24;

    private const TOKEN_NAME = 'api';

    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_LOCKOUT_MINUTES = 15;

    public function __construct(
        private UserRepositoryInterface $users,
        private OtpService $otp,
        private AuditLogService $audit,
        private StaffAccountService $staffAccounts,
    ) {}

    public function register(array $data): array
    {
        $data['email'] = mb_strtolower(trim($data['email']));
        $existing = $this->users->findByWhatsapp($data['whatsapp_number']);

        // Invited staff accounts awaiting activation are not resumable registrations.
        if ($existing?->isVerified() || ($existing && $existing->role !== UserRole::PATIENT)) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('This WhatsApp number is already registered.'),
            ]);
        }

        $created = false;

        $user = DB::transaction(function () use ($existing, $data, &$created) {
            $existing = $existing ? User::whereKey($existing->id)->lockForUpdate()->first() : null;
            if ($existing?->isVerified() || ($existing && $existing->role !== UserRole::PATIENT)) {
                throw ValidationException::withMessages([
                    'whatsapp_number' => __('This WhatsApp number is already registered.'),
                ]);
            }

            if ($existing && $this->isUnverifiedExpired($existing)) {
                $this->users->delete($existing);
                $existing = null;
            }

            if ($existing) {
                $this->assertEmailAvailable($data['email'], $existing);

                return $existing;
            }

            $this->assertEmailAvailable($data['email']);

            $user = $this->users->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => UserRole::PATIENT->value,
                'whatsapp_number' => $data['whatsapp_number'],
                'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
                'is_active' => false,
                'phone_verified_at' => null,
                'login_attempts' => 0,
                'privacy_policy_version' => self::privacyPolicyVersion(),
                'privacy_accepted_at' => now(),
            ]);

            $user->assignRole(UserRole::PATIENT->value);
            $created = true;

            return $user;
        });

        try {
            $this->otp->send($user, OtpService::PURPOSE_REGISTRATION);
        } catch (\Throwable $e) {
            // No code could be delivered: do not leave a half-registered row
            // holding the number/email hostage until the unverified TTL expires.
            if ($created) {
                $this->users->delete($user);
            }

            throw $e;
        }

        return [
            'message' => __('Account created. Enter the verification code sent to your WhatsApp.'),
            'user_id' => $user->id,
        ];
    }

    public function verifyOtp(string $whatsappNumber, string $code): array
    {
        return DB::transaction(function () use ($whatsappNumber, $code): array {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();

            // One indistinguishable failure for unknown, already-verified and wrong-code
            // cases so the endpoint cannot be used to enumerate accounts.
            if (! $user || $user->isVerified() || $user->role !== UserRole::PATIENT || ! $this->otp->verify($user, $code, OtpService::PURPOSE_REGISTRATION)) {
                throw ValidationException::withMessages([
                    'otp' => __('Invalid or expired verification code.'),
                ]);
            }

            $this->users->update($user, [
                'phone_verified_at' => now(),
                'is_active' => true,
                'login_attempts' => 0,
            ]);

            Log::info('User account activated', ['user_id' => $user->id]);

            return array_merge(
                ['message' => __('Account verified successfully.'), 'user' => $user->refresh()],
                $this->issueToken($user)
            );
        });
    }

    /** Redeem a management-issued activation code and sign the new staff member in. */
    public function activate(string $whatsappNumber, string $code, string $password): array
    {
        $result = DB::transaction(function () use ($whatsappNumber, $code, $password): ?array {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();

            // One indistinguishable failure for unknown numbers, active accounts and bad codes.
            if (! $user || ! $this->staffAccounts->activate($user, $code, $password)) {
                return null;
            }

            $user = $user->refresh();
            Log::info('Staff account activated', ['user_id' => $user->id]);

            return array_merge(
                ['message' => __('Account activated successfully.'), 'user' => $user],
                $this->issueToken($user)
            );
        });

        if ($result === null) {
            throw ValidationException::withMessages(['code' => __('Invalid or expired activation code.')]);
        }

        return $result;
    }

    public function resendOtp(string $whatsappNumber): array
    {
        DB::transaction(function () use ($whatsappNumber): void {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();
            if ($user && ! $user->isVerified() && $user->role === UserRole::PATIENT) {
                if ($this->isUnverifiedExpired($user)) {
                    $this->users->delete($user);
                } else {
                    $this->sendOtpQuietly($user, OtpService::PURPOSE_REGISTRATION);
                }
            }
        });

        // Same response whether the number is unknown, verified, expired or on cooldown.
        return ['message' => __('If this number is pending verification, a new code has been sent.')];
    }

    public function login(array $data): array
    {
        $user = $this->users->findByWhatsapp($data['whatsapp_number']);

        $this->assertNotLockedOut($data['whatsapp_number']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->recordFailedLogin($user, $data['whatsapp_number']);
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Invalid credentials.'),
            ]);
        }

        try {
            $this->assertLoginable($user);
        } catch (ValidationException $e) {
            $this->recordFailedLogin($user, $data['whatsapp_number']);
            throw $e;
        }

        // Staff roles must pass a second factor (WhatsApp OTP) before a token
        // is minted. Patient and therapist logins stay one-factor.
        if ($this->requires2fa($user)) {
            return DB::transaction(function () use ($user): array {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $this->assertCredentialProof($locked, $user);
                $this->assertLoginable($locked);
                $this->otp->send($locked, OtpService::PURPOSE_LOGIN_2FA);
                $challenge = Str::random(64);
                $expiresAt = now()->addMinutes(5);
                DB::table('auth_login_challenges')->where('user_id', $locked->id)->delete();
                DB::table('auth_login_challenges')->insert([
                    'token_hash' => hash('sha256', $challenge),
                    'user_id' => $locked->id,
                    'credential_version' => $locked->credential_version,
                    'expires_at' => $expiresAt,
                ]);

                return [
                    'requires_2fa' => true,
                    'login_challenge' => $challenge,
                    'challenge_expires_at' => $expiresAt->toISOString(),
                    'message' => __('A verification code was sent to your WhatsApp number.'),
                ];
            });
        }

        $tokens = $this->completeLogin($user);
        $user = $user->refresh();

        return array_merge(['message' => __('Login successful.'), 'user' => $user], $tokens);
    }

    /**
     * Second factor of the staff login: verifies the OTP sent by login() and
     * only then mints tokens.
     */
    public function verifyLoginOtp(string $whatsappNumber, string $code, ?string $challenge = null): array
    {
        return DB::transaction(function () use ($whatsappNumber, $code, $challenge): array {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();
            $this->assertLoginChallenge($user, $challenge);
            $this->assertLoginable($user);

            if (! $this->otp->verify($user, $code, OtpService::PURPOSE_LOGIN_2FA)) {
                throw ValidationException::withMessages(['otp' => __('Invalid or expired verification code.')]);
            }

            DB::table('auth_login_challenges')->where('user_id', $user->id)->delete();
            $tokens = $this->completeLogin($user);

            return array_merge(['message' => __('Login successful.'), 'user' => $user->refresh()], $tokens);
        });
    }

    /** Re-send the staff login OTP; same generic response either way. */
    public function resendLoginOtp(string $whatsappNumber, ?string $challenge = null): array
    {
        return DB::transaction(function () use ($whatsappNumber, $challenge): array {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();
            $this->assertLoginChallenge($user, $challenge);
            $this->assertLoginable($user);
            $this->sendOtpQuietly($user, OtpService::PURPOSE_LOGIN_2FA);

            return ['message' => __('If a staff sign-in is pending, a new code has been sent.')];
        });
    }

    private function assertLoginChallenge(?User $user, ?string $challenge): void
    {
        $stored = $challenge ? DB::table('auth_login_challenges')->where('token_hash', hash('sha256', $challenge))->first() : null;

        if (! $user || ! $this->requires2fa($user) || ! $stored
            || $stored->user_id !== $user->id
            || (int) $stored->credential_version !== (int) $user->credential_version
            || now()->greaterThanOrEqualTo($stored->expires_at)) {
            throw ValidationException::withMessages(['login_challenge' => __('Invalid or expired sign-in challenge. Sign in with your password again.')]);
        }
    }

    private function assertCredentialProof(User $locked, User $proof): void
    {
        if ((int) $locked->credential_version !== (int) $proof->credential_version
            || ! hash_equals((string) $locked->password, (string) $proof->password)) {
            throw ValidationException::withMessages(['whatsapp_number' => __('Credentials changed. Please sign in again.')]);
        }
    }

    private function requires2fa(User $user): bool
    {
        return ! in_array($user->role, [UserRole::PATIENT, UserRole::THERAPIST], true);
    }

    /**
     * Mint tokens for an authenticated user. The user row is locked while the
     * token is minted so a concurrent anonymisation/deactivation either runs
     * first (login is refused) or after (its revokeAllTokens() sees and
     * deletes this token).
     */
    private function completeLogin(User $user): array
    {
        [$user, $tokens] = DB::transaction(function () use ($user): array {
            $proof = $user;
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCredentialProof($user, $proof);
            $this->assertLoginable($user);

            $update = ['login_attempts' => 0, 'last_login' => now()];

            // Logging back in during the grace period cancels scheduled deletion.
            if ($user->deletion_scheduled_at !== null) {
                $update['deletion_scheduled_at'] = null;
                $this->audit->record($user, AuditLogService::ACCOUNT_DELETION_CANCELLED, $user->id);
            }

            $this->users->update($user, $update);
            $this->audit->record($user, AuditLogService::LOGIN_SUCCEEDED, $user->id);

            return [$user->refresh(), $this->issueToken($user)];
        });

        Cache::forget($this->lockoutKey($user->whatsapp_number));
        Cache::forget($this->attemptsKey($user->whatsapp_number));

        return $tokens;
    }

    private function assertLoginable(User $user): void
    {
        if (! $user->isVerified() || ! $user->is_active || $user->anonymized_at !== null) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Account is not verified. Please verify your WhatsApp number first.'),
            ]);
        }
    }

    public function logout(User $user): array
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            DB::transaction(function () use ($user, $token): void {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                RefreshToken::where('access_token_id', $token->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
                $token->delete();
                CredentialsRevoked::dispatch((string) $user->id);
            });
        }

        return ['message' => __('Logged out successfully.')];
    }

    public static function privacyPolicyVersion(): string
    {
        return (string) config('sakina.privacy_policy.version', 'unversioned');
    }

    /**
     * Authenticated password change. Every other device is signed out; the
     * caller keeps its current access token.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('The new password must differ from the current one.'),
            ]);
        }

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        DB::transaction(function () use ($user, $newPassword, $currentId) {
            $proof = $user;
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCredentialProof($user, $proof);
            $this->users->update($user, [
                'password' => Hash::make($newPassword),
                'password_changed_at' => now(),
            ]);

            $user->tokens()->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))->delete();
            $user->refreshTokens()->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->record($user, AuditLogService::PASSWORD_CHANGED, $user->id);
            CredentialsRevoked::dispatch((string) $user->id);
        });

        return ['message' => __('Password changed. Other devices have been signed out.')];
    }

    public function logoutAll(User $user): array
    {
        $user->revokeAllTokens();
        $this->audit->record($user, AuditLogService::LOGOUT_ALL, $user->id);

        return ['message' => __('Logged out from all devices.')];
    }

    /**
     * Rotate a refresh token: the presented token is single-use; a replay of
     * an already-used or revoked token means it leaked, so the whole session
     * family (every access + refresh token of the user) is revoked.
     */
    public function refresh(string $plainRefreshToken): array
    {
        $hash = hash('sha256', $plainRefreshToken);

        $stored = RefreshToken::where('token_hash', $hash)->first();

        if (! $stored) {
            $this->invalidRefresh();
        }

        $user = $stored->user;

        if ($stored->revoked_at !== null) {
            $this->invalidRefresh();
        }

        if ($stored->used_at !== null) {
            Log::warning('Refresh token replay detected; revoking all sessions', [
                'user_id' => $stored->user_id,
                'family_id' => $stored->family_id,
            ]);

            if ($user) {
                $user->revokeAllTokens();
                $this->audit->record($user, AuditLogService::REFRESH_TOKEN_REPLAYED, $user->id, [
                    'family_id' => $stored->family_id,
                ]);
            }

            $this->invalidRefresh();
        }

        if ($stored->expires_at->isPast() || ! $user || ! $user->is_active || ! $user->phone_verified_at) {
            $stored->update(['revoked_at' => now()]);
            $this->invalidRefresh();
        }

        return DB::transaction(function () use ($stored, $user): array {
            $proof = $user;
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCredentialProof($user, $proof);

            if (! $user->is_active || $user->anonymized_at !== null || ! $user->phone_verified_at
                || (int) $stored->credential_version !== (int) $user->credential_version
                || ! $stored->expires_at->isFuture()) {
                $this->invalidRefresh();
            }

            // Single-use: the atomic claim guarantees two concurrent refreshes with
            // the same token cannot both succeed.
            $claimed = RefreshToken::whereKey($stored->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['used_at' => now()]);

            if ($claimed === 0) {
                $this->invalidRefresh();
            }

            PersonalAccessToken::whereKey($stored->access_token_id)->delete();

            return $this->issueToken($user, $stored->family_id);
        });
    }

    private function invalidRefresh(): never
    {
        throw ValidationException::withMessages([
            'refresh_token' => __('Invalid or expired refresh token.'),
        ]);
    }

    public function updateTimezone(User $user, string $timezone): array
    {
        $user->forceFill(['timezone' => $timezone])->save();

        return $this->currentUser($user);
    }

    public function currentUser(User $user): array
    {
        return [
            'user' => $user->loadMissing('patient'),
            'roles' => $user->getRoleNames(),
        ];
    }

    public function checkStatus(string $whatsappNumber): array
    {
        $user = $this->users->findByWhatsapp($whatsappNumber);

        // Only reveal the state a legitimate owner needs mid-onboarding:
        // whether an OTP verification is still pending for this number.
        return [
            'verification_pending' => $user !== null && ! $user->isVerified(),
        ];
    }

    public function forgotPassword(string $whatsappNumber): array
    {
        $user = $this->users->findByWhatsapp($whatsappNumber);

        // Do not reveal whether the number is registered.
        if ($user?->isVerified()) {
            $this->sendOtpQuietly($user, OtpService::PURPOSE_PASSWORD_RESET);
        }

        return [
            'message' => __('If this number is registered, a reset code has been sent.'),
        ];
    }

    public function resetPassword(string $whatsappNumber, string $code, string $newPassword): array
    {
        return DB::transaction(function () use ($whatsappNumber, $code, $newPassword): array {
            $user = User::whereIn('whatsapp_number', PhoneNumber::variants($whatsappNumber))->lockForUpdate()->first();

            if (! $user || ! $this->otp->verify($user, $code, OtpService::PURPOSE_PASSWORD_RESET)) {
                throw ValidationException::withMessages([
                    'otp' => __('Invalid or expired reset code.'),
                ]);
            }

            $this->users->update($user, ['password' => Hash::make($newPassword), 'password_changed_at' => now()]);

            // Revoke all existing tokens so the new password is required everywhere.
            $user->revokeAllTokens();

            $this->audit->record($user, AuditLogService::PASSWORD_RESET, $user->id);
            Log::info('Password reset completed', ['user_id' => $user->id]);

            return ['message' => __('Password updated successfully.')];
        });
    }

    /**
     * Send an OTP without surfacing cooldown/provider errors to the caller;
     * those responses would confirm the account exists.
     */
    private function sendOtpQuietly(User $user, string $purpose): void
    {
        try {
            $this->otp->send($user, $purpose);
        } catch (ValidationException $e) {
            Log::info('OTP not sent', ['user_id' => $user->id, 'purpose' => $purpose, 'reason' => $e->getMessage()]);
        }
    }

    private function issueToken(User $user, ?string $familyId = null): array
    {
        return DB::transaction(function () use ($user, $familyId): array {
            $proof = $user;
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCredentialProof($user, $proof);
            $this->assertLoginable($user);
            $expiresAt = now()->addMinutes((int) config('sakina.access_token_ttl_minutes', 120));
            $token = $user->createToken(self::TOKEN_NAME, ['*'], $expiresAt);

            $refreshExpiresAt = now()->addDays((int) config('sakina.refresh_token_ttl_days', 15));
            $plainRefresh = Str::random(64);

            (new RefreshToken)->forceFill([
                'user_id' => $user->id,
                'family_id' => $familyId ?? (string) Str::uuid(),
                'token_hash' => hash('sha256', $plainRefresh),
                'access_token_id' => $token->accessToken->getKey(),
                'expires_at' => $refreshExpiresAt,
                'credential_version' => $user->credential_version,
            ])->save();

            return [
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
                'refresh_token' => $plainRefresh,
                'refresh_expires_at' => $refreshExpiresAt->toISOString(),
            ];
        });
    }

    private function isUnverifiedExpired(User $user): bool
    {
        return $user->created_at?->addHours(self::UNVERIFIED_USER_TTL_HOURS)->isPast() ?? false;
    }

    private function assertEmailAvailable(string $email, ?User $except = null): void
    {
        $existing = $this->users->findByEmail($email);

        if ($existing && $existing->id !== $except?->id) {
            throw ValidationException::withMessages([
                'email' => __('This email is already registered.'),
            ]);
        }
    }

    private function assertNotLockedOut(string $whatsappNumber): void
    {
        if (Cache::has($this->lockoutKey($whatsappNumber))) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Too many failed attempts. Try again in :minutes minutes.', [
                    'minutes' => self::LOGIN_LOCKOUT_MINUTES,
                ]),
            ]);
        }
    }

    /**
     * Failed attempts are counted per normalised number whether or not it
     * belongs to an account, so the lockout response cannot be used to tell
     * registered numbers from unknown ones.
     */
    private function recordFailedLogin(?User $user, string $whatsappNumber): void
    {
        $key = $this->attemptsKey($whatsappNumber);
        $ttl = now()->addMinutes(self::LOGIN_LOCKOUT_MINUTES);

        Cache::add($key, 0, $ttl);
        $attempts = (int) Cache::increment($key);

        if ($user) {
            $this->users->update($user, ['login_attempts' => $attempts]);
        }

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            Cache::put($this->lockoutKey($whatsappNumber), true, $ttl);
            Cache::forget($key);

            if ($user) {
                $this->users->update($user, ['login_attempts' => 0]);
                Log::warning('Account locked after failed logins', ['user_id' => $user->id]);
            }
        }
    }

    private function lockoutKey(string $whatsappNumber): string
    {
        return 'login_lock:'.$this->normalizeNumber($whatsappNumber);
    }

    private function attemptsKey(string $whatsappNumber): string
    {
        return 'login_attempts:'.$this->normalizeNumber($whatsappNumber);
    }

    private function normalizeNumber(string $whatsappNumber): string
    {
        return preg_replace('/\D+/', '', $whatsappNumber);
    }
}
