<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Issues and verifies one-time codes delivered over WhatsApp.
 *
 * Security properties:
 * - OTPs are stored as HMAC hashes, never in plaintext.
 * - Codes are scoped by purpose (registration vs password reset).
 * - Verification is limited to MAX_ATTEMPTS wrong tries.
 * - Resends are rate-limited by a cooldown window.
 */
class OtpService
{
    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    private const CODE_LENGTH = 6;

    private const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private WhatsAppSenderInterface $whatsApp,
    ) {}

    /**
     * Generate and send a fresh OTP for the given purpose.
     *
     * @throws ValidationException when a resend cooldown is still active.
     */
    public function send(User $user, string $purpose): void
    {
        $cooldownKey = $this->cooldownKey($user, $purpose);
        $remaining = (int) Cache::get($cooldownKey, 0) - now()->getTimestamp();

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'otp' => __('Please wait :seconds seconds before requesting a new code.', ['seconds' => $remaining]),
            ]);
        }

        $code = $this->generateCode();

        Cache::put($this->codeKey($user, $purpose), $this->hash($code), now()->addMinutes(self::TTL_MINUTES));
        Cache::put($this->attemptsKey($user, $purpose), 0, now()->addMinutes(self::TTL_MINUTES));
        Cache::put($cooldownKey, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->getTimestamp(), now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));

        try {
            $sent = $this->whatsApp->send($user->whatsapp_number, $this->message($code));
        } catch (\Throwable $e) {
            Log::error('OTP dispatch failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $sent = false;
        }

        if (! $sent) {
            Cache::forget($this->codeKey($user, $purpose));
            Cache::forget($this->attemptsKey($user, $purpose));
            Cache::forget($cooldownKey);
            throw ValidationException::withMessages([
                'otp' => __('Failed to send the verification code. Please try again later.'),
            ]);
        }
    }

    /**
     * Verify a user-entered code for the given purpose.
     * Returns false for wrong, expired, or exhausted codes.
     */
    public function verify(User $user, string $code, string $purpose): bool
    {
        $storedHash = Cache::get($this->codeKey($user, $purpose));

        if (! is_string($storedHash)) {
            return false;
        }

        if (hash_equals($storedHash, $this->hash($code))) {
            $this->invalidate($user, $purpose);

            return true;
        }

        $attempts = (int) Cache::increment($this->attemptsKey($user, $purpose));

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->invalidate($user, $purpose);
            Log::warning('OTP attempt limit reached', ['user_id' => $user->id, 'purpose' => $purpose]);
        }

        return false;
    }

    public function invalidate(User $user, string $purpose): void
    {
        Cache::forget($this->codeKey($user, $purpose));
        Cache::forget($this->attemptsKey($user, $purpose));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function message(string $code): string
    {
        return sprintf(
            'Your Sakina verification code is: %s. It is valid for %d minutes.',
            $code,
            self::TTL_MINUTES
        );
    }

    private function normalizedPhone(User $user): string
    {
        return preg_replace('/\D+/', '', (string) $user->whatsapp_number);
    }

    private function codeKey(User $user, string $purpose): string
    {
        return "otp:{$purpose}:{$this->normalizedPhone($user)}";
    }

    private function attemptsKey(User $user, string $purpose): string
    {
        return "otp_attempts:{$purpose}:{$this->normalizedPhone($user)}";
    }

    private function cooldownKey(User $user, string $purpose): string
    {
        return "otp_cooldown:{$purpose}:{$this->normalizedPhone($user)}";
    }
}
