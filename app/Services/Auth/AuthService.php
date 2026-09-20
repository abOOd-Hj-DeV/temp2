<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\RefreshToken;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuditLogService;
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
 * Registration always creates a PATIENT. Elevated roles are granted
 * server-side by admins — never through this endpoint.
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
    ) {}

    public function register(array $data): array
    {
        $data['email'] = mb_strtolower(trim($data['email']));
        $existing = $this->users->findByWhatsapp($data['whatsapp_number']);

        if ($existing?->isVerified()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('This WhatsApp number is already registered.'),
            ]);
        }

        $created = false;

        $user = DB::transaction(function () use ($existing, $data, &$created) {
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
                'is_active' => false,
                'phone_verified_at' => null,
                'login_attempts' => 0,
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

        // A pending account only takes on the new details once a code was
        // actually delivered; a throttled or failed send leaves it untouched.
        if (! $created) {
            $user = DB::transaction(function () use ($user, $data) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($user->isVerified()) {
                    throw ValidationException::withMessages([
                        'whatsapp_number' => __('This WhatsApp number is already registered.'),
                    ]);
                }

                $this->assertEmailAvailable($data['email'], $user);
                $this->users->update($user, [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);

                return $user->refresh();
            });
        }

        return [
            'message' => __('Account created. Enter the verification code sent to your WhatsApp.'),
            'user_id' => $user->id,
        ];
    }

    public function verifyOtp(string $whatsappNumber, string $code): array
    {
        $user = $this->users->findByWhatsapp($whatsappNumber);

        // One indistinguishable failure for unknown, already-verified and wrong-code
        // cases so the endpoint cannot be used to enumerate accounts.
        if (! $user || $user->isVerified() || ! $this->otp->verify($user, $code, OtpService::PURPOSE_REGISTRATION)) {
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
    }

    public function resendOtp(string $whatsappNumber): array
    {
        $user = $this->users->findByWhatsapp($whatsappNumber);

        if ($user && ! $user->isVerified()) {
            if ($this->isUnverifiedExpired($user)) {
                $this->users->delete($user);
            } else {
                $this->sendOtpQuietly($user, OtpService::PURPOSE_REGISTRATION);
            }
        }

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

        // The user row is locked while the token is minted so a concurrent
        // anonymisation/deactivation either runs first (login is refused) or
        // after (its revokeAllTokens() sees and deletes this token).
        [$user, $tokens] = DB::transaction(function () use ($user): array {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
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

        Cache::forget($this->lockoutKey($data['whatsapp_number']));
        Cache::forget($this->attemptsKey($data['whatsapp_number']));

        return array_merge(['message' => __('Login successful.'), 'user' => $user], $tokens);
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
            RefreshToken::where('access_token_id', $token->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $token->delete();
        }

        return ['message' => __('Logged out successfully.')];
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
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $user->is_active || $user->anonymized_at !== null || ! $user->phone_verified_at) {
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
        $user = $this->users->findByWhatsapp($whatsappNumber);

        if (! $user || ! $this->otp->verify($user, $code, OtpService::PURPOSE_PASSWORD_RESET)) {
            throw ValidationException::withMessages([
                'otp' => __('Invalid or expired reset code.'),
            ]);
        }

        $this->users->update($user, ['password' => Hash::make($newPassword)]);

        // Revoke all existing tokens so the new password is required everywhere.
        $user->revokeAllTokens();

        $this->audit->record($user, AuditLogService::PASSWORD_RESET, $user->id);
        Log::info('Password reset completed', ['user_id' => $user->id]);

        return ['message' => __('Password updated successfully.')];
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
        $expiresAt = now()->addMinutes((int) config('sakina.access_token_ttl_minutes', 120));
        $token = $user->createToken(self::TOKEN_NAME, ['*'], $expiresAt);

        $refreshExpiresAt = now()->addDays((int) config('sakina.refresh_token_ttl_days', 15));
        $plainRefresh = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::uuid(),
            'token_hash' => hash('sha256', $plainRefresh),
            'access_token_id' => $token->accessToken->getKey(),
            'expires_at' => $refreshExpiresAt,
        ]);

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'refresh_token' => $plainRefresh,
            'refresh_expires_at' => $refreshExpiresAt->toISOString(),
        ];
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
