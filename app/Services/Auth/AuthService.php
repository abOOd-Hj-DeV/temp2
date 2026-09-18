<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    private const TOKEN_TTL_DAYS = 15;

    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_LOCKOUT_MINUTES = 15;

    public function __construct(
        private UserRepositoryInterface $users,
        private OtpService $otp,
        private AuditLogService $audit,
    ) {}

    public function register(array $data): array
    {
        $existing = $this->users->findByWhatsapp($data['whatsapp_number']);

        if ($existing?->isVerified()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('This WhatsApp number is already registered.'),
            ]);
        }

        $user = DB::transaction(function () use ($existing, $data) {
            if ($existing && $this->isUnverifiedExpired($existing)) {
                $this->users->delete($existing);
                $existing = null;
            }

            if ($existing) {
                $this->assertEmailAvailable($data['email'], $existing);
                $this->users->update($existing, [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);

                return $existing->refresh();
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

            return $user;
        });

        $this->otp->send($user, OtpService::PURPOSE_REGISTRATION);

        return [
            'message' => __('Account created. Enter the verification code sent to your WhatsApp.'),
            'user_id' => $user->id,
        ];
    }

    public function verifyOtp(string $whatsappNumber, string $code): array
    {
        $user = $this->users->findByWhatsapp($whatsappNumber);

        if (! $user) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('This WhatsApp number is not registered.'),
            ]);
        }

        if ($user->isVerified()) {
            throw ValidationException::withMessages([
                'otp' => __('Account is already verified. Please log in.'),
            ]);
        }

        if (! $this->otp->verify($user, $code, OtpService::PURPOSE_REGISTRATION)) {
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

        if (! $user) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('This WhatsApp number is not registered.'),
            ]);
        }

        if ($user->isVerified()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Account is already verified. Please log in.'),
            ]);
        }

        if ($this->isUnverifiedExpired($user)) {
            $this->users->delete($user);
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Verification period expired. Please register again.'),
            ]);
        }

        $this->otp->send($user, OtpService::PURPOSE_REGISTRATION);

        return ['message' => __('A new verification code was sent.')];
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

        if (! $user->isVerified() || ! $user->is_active) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('Account is not verified. Please verify your WhatsApp number first.'),
            ]);
        }

        $update = ['login_attempts' => 0, 'last_login' => now()];

        // Logging back in during the grace period cancels scheduled deletion.
        if ($user->deletion_scheduled_at !== null) {
            $update['deletion_scheduled_at'] = null;
            $this->audit->record($user, AuditLogService::ACCOUNT_DELETION_CANCELLED, $user->id);
        }

        $this->users->update($user, $update);
        Cache::forget($this->lockoutKey($data['whatsapp_number']));
        $this->audit->record($user, AuditLogService::LOGIN_SUCCEEDED, $user->id);

        return array_merge(
            ['message' => __('Login successful.'), 'user' => $user->refresh()],
            $this->issueToken($user)
        );
    }

    public function logout(User $user): array
    {
        $user->currentAccessToken()?->delete();

        return ['message' => __('Logged out successfully.')];
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
            $this->otp->send($user, OtpService::PURPOSE_PASSWORD_RESET);
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
        $user->tokens()->delete();

        $this->audit->record($user, AuditLogService::PASSWORD_RESET, $user->id);
        Log::info('Password reset completed', ['user_id' => $user->id]);

        return ['message' => __('Password updated successfully.')];
    }

    private function issueToken(User $user): array
    {
        $expiresAt = now()->addDays(self::TOKEN_TTL_DAYS);
        $token = $user->createToken(self::TOKEN_NAME, ['*'], $expiresAt);

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
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

    private function recordFailedLogin(?User $user, string $whatsappNumber): void
    {
        if (! $user) {
            return;
        }

        $attempts = $user->login_attempts + 1;
        $this->users->update($user, ['login_attempts' => $attempts]);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            Cache::put(
                $this->lockoutKey($whatsappNumber),
                true,
                now()->addMinutes(self::LOGIN_LOCKOUT_MINUTES)
            );
            $this->users->update($user, ['login_attempts' => 0]);
            Log::warning('Account locked after failed logins', ['user_id' => $user->id]);
        }
    }

    private function lockoutKey(string $whatsappNumber): string
    {
        return 'login_lock:'.preg_replace('/\D+/', '', $whatsappNumber);
    }
}
