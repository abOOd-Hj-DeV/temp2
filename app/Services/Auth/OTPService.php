<?php
// app/Services/Auth/OTPService.php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\UltraMsg\UltraMsgService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class OTPService
{
    private const OTP_LENGTH = 6;
    private const OTP_LIFETIME_MINUTES = 5;
    private const CACHE_KEY_PREFIX = 'otp:';

    private UltraMsgService $ultraMsgService;

    public function __construct(UltraMsgService $ultraMsgService)
    {
        $this->ultraMsgService = $ultraMsgService;
    }

    /**
     * Send OTP to user's WhatsApp number
     */
    public function sendOTP(User $user): bool
    {
        $otp = $this->generateOTP();
        $key = $this->generateCacheKey($user->whatsapp_number);

        // Store OTP in cache with expiration
        Cache::put($key, $otp, now()->addMinutes(self::OTP_LIFETIME_MINUTES));

        // Generate OTP message
        $message = $this->generateOTPMessage($otp);

        try {
            // Send OTP via WhatsApp
            $success = $this->ultraMsgService->sendWhatsAppMessage($user->whatsapp_number, $message);

            if (!$success) {
                $this->cleanupFailedOTP($key, $user->whatsapp_number);
                return false;
            }

            Log::info('OTP sent successfully', [
                'user_id' => $user->id,
                'whatsapp_number' => $user->whatsapp_number,
            ]);

            return true;

        } catch (Exception $e) {
            $this->cleanupFailedOTP($key, $user->whatsapp_number);
            Log::error('Failed to send OTP via UltraMsg', [
                'whatsapp_number' => $user->whatsapp_number,
                'error' => $e->getMessage()
            ]);

            throw new Exception('Failed to send verification code via WhatsApp.');
        }
    }

    /**
     * Verify OTP entered by user
     */
    public function verifyOTP(User $user, string $otp): bool
    {
        $key = $this->generateCacheKey($user->whatsapp_number);
        $storedOtp = Cache::get($key);

        // Validate OTP
        if (!$storedOtp || $storedOtp !== $otp) {
            Log::warning('Invalid OTP attempt', [
                'user_id' => $user->id,
                'whatsapp_number' => $user->whatsapp_number,
            ]);
            return false;
        }

        // Remove OTP after successful verification
        Cache::forget($key);

        Log::info('OTP verified successfully', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Delete OTPs for a specific user
     */
    public function deleteOldOTPs(User $user): void
    {
        $key = $this->generateCacheKey($user->whatsapp_number);

        if (Cache::has($key)) {
            Cache::forget($key);
            Log::debug('Old OTPs deleted', [
                'whatsapp_number' => $user->whatsapp_number,
            ]);
        }
    }

    /**
     * Check if user has active OTP
     */
    public function hasActiveOTP(User $user): bool
    {
        return Cache::has($this->generateCacheKey($user->whatsapp_number));
    }

    /**
     * Get remaining time for OTP in seconds
     */
    public function getRemainingTime(User $user): ?int
    {
        // This method only works with Redis cache driver
        if (config('cache.default') !== 'redis') {
            return null;
        }

        try {
            $redis = Cache::store('redis')->getRedis();
            $ttl = $redis->ttl($this->generateCacheKey($user->whatsapp_number));

            return $ttl > 0 ? $ttl : 0;

        } catch (Exception $e) {
            Log::warning('Could not get OTP TTL', [
                'whatsapp_number' => $user->whatsapp_number,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Delete OTP by phone number (alternative method)
     */
    public function deleteOTPByPhoneNumber(string $phoneNumber): void
    {
        $key = $this->generateCacheKey($phoneNumber);

        if (Cache::has($key)) {
            Cache::forget($key);
            Log::debug('OTP deleted by phone number', [
                'phone_number' => $phoneNumber,
            ]);
        }
    }

    /**
     * ========== PRIVATE METHODS ==========
     */

    private function generateOTP(): string
    {
        return str_pad(random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    private function generateCacheKey(string $phoneNumber): string
    {
        // Clean phone number (remove non-numeric characters)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        return self::CACHE_KEY_PREFIX . $cleanPhone;
    }

    private function generateOTPMessage(string $otp): string
    {
        return sprintf(
            "Your verification code is: %s. Valid for %d minutes.",
            $otp,
            self::OTP_LIFETIME_MINUTES
        );
    }

    private function cleanupFailedOTP(string $cacheKey, string $whatsappNumber): void
    {
        Cache::forget($cacheKey);
        Log::error('OTP sending failed', [
            'whatsapp_number' => $whatsappNumber,
        ]);
    }
}
