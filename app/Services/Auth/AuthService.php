<?php
// app/Services/Auth/AuthService.php

namespace App\Services\Auth;

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Repositories\Contracts\UserRepositoryInterface;
use Exception;

class AuthService
{
    private const UNVERIFIED_USER_EXPIRY_HOURS = 24;
    private const MAX_LOGIN_ATTEMPTS = 5;

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private OTPService $otpService
    ) {}

    /**
     * Register a new user or update inactive user
     */
    public function register(array $data): array
    {
        // First, clean up any expired unverified users
        $this->cleanupExpiredUnverifiedUser($data['whatsapp_number']);

        // Check for active user with same WhatsApp number
        $activeUser = $this->userRepository->findActiveByWhatsapp($data['whatsapp_number']);
        if ($activeUser) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'This WhatsApp number is already registered and active.'
            ]);
        }

        return DB::transaction(function () use ($data) {
            // Find any user with this WhatsApp number (regardless of status)
            $existingUser = $this->userRepository->findByWhatsapp($data['whatsapp_number']);

            if ($existingUser) {
                // Check if this user is unverified (not active and not phone verified)
                if (!$existingUser->phone_verified_at && !$existingUser->is_active) {
                    // Update existing unverified user
                    return $this->updateExistingUser($existingUser, $data);
                } else {
                    // User exists but is in a state we don't expect
                    // This shouldn't happen if cleanup worked correctly
                    throw ValidationException::withMessages([
                        'whatsapp_number' => 'Account status is inconsistent. Please contact support.'
                    ]);
                }
            }

            // No existing user found - create new one
            return $this->createNewUser($data);
        });
    }

    /**
     * Verify OTP and activate account
     */
    public function verifyOTP(string $whatsappNumber, string $otp): array
{
    $user = $this->userRepository->findByWhatsapp($whatsappNumber);

    if (!$user) {
        throw ValidationException::withMessages([
            'whatsapp_number' => 'WhatsApp number is not registered.'
        ]);
    }

    if ($this->otpService->verifyOTP($user, $otp)) {
        return DB::transaction(function () use ($user) {
            try {
                // ⭐⭐⭐⭐ الإصلاح هنا: تأكد من تعيين كلا الحقلين ⭐⭐⭐⭐
                $updateData = [
                    'phone_verified_at' => now(), // ⬅️ هذا الأهم
                    'is_active' => true,
                    'login_attempts' => 0,
                ];

                $updateSuccess = $this->userRepository->update($user, $updateData);

                if (!$updateSuccess) {
                    throw new \Exception('Failed to update user activation status.');
                }

                // تأكد من تحديث الكائن المحلي
                $user->refresh();

                // تسجيل عملية التفعيل
                \Log::info('User OTP verified and account activated', [
                    'user_id' => $user->id,
                    'whatsapp' => $user->whatsapp_number,
                    'phone_verified_at' => $user->phone_verified_at,
                    'is_active' => $user->is_active,
                ]);

                // إصدار التوكن
                $tokenResult = $user->createToken('Personal Access Token');

                return [
                    'message' => 'Account activated successfully.',
                    'user' => $user,
                    'token' => $tokenResult->accessToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $tokenResult->token->expires_at->toDateTimeString(),
                ];

            } catch (\Exception $e) {
                \Log::error('OTP verification failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                throw ValidationException::withMessages([
                    'database' => 'Failed to activate account. Please try again.'
                ]);
            }
        });
    }

    throw ValidationException::withMessages([
        'otp' => 'Invalid or expired verification code.'
    ]);
}

    /**
     * User login with WhatsApp number and password
     */
    public function login(array $credentials): array
    {
        $user = $this->userRepository->findActiveByWhatsapp($credentials['whatsapp_number']);
        $this->validateLoginCredentials($user, $credentials['password']);
        $this->validateUserVerification($user);
        $this->validateUserActivation($user);
        $this->validateLoginAttempts($user);

        // Reset login attempts on successful login
        $this->resetLoginAttempts($user);

        // Update last login timestamp
        $this->userRepository->updateLastLogin($user);

        // Create new authentication token
        $token = $this->createAuthToken($user);

        return [
            'user' => $user,
            'token' => $token->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->token->expires_at->toDateTimeString(),
        ];
    }

    /**
     * Resend OTP for unverified users
     */
    public function resendOTP(string $whatsappNumber): array
    {
        $user = $this->userRepository->findByWhatsapp($whatsappNumber);

        if (!$user) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'WhatsApp number is not registered.'
            ]);
        }

        // Check if account is already activated
        if ($user->phone_verified_at && $user->is_active) {
            throw ValidationException::withMessages([
                'message' => 'Account is already activated.'
            ]);
        }

        // Check if verification period has expired
        if ($this->isUnverifiedUserExpired($user)) {
            $this->userRepository->delete($user);
            throw ValidationException::withMessages([
                'whatsapp_number' => 'Verification period has expired. Please register again.'
            ]);
        }

        return DB::transaction(function () use ($user) {
            // Delete old OTPs
            $this->otpService->deleteOldOTPs($user);

            // Send new OTP
            $this->otpService->sendOTP($user);

            return [
                'message' => 'New verification code sent successfully.',
                'user_id' => $user->id,
                'remaining_time' => $this->getRemainingVerificationTime($user),
            ];
        });
    }

    /**
     * Logout user by revoking tokens
     */
    public function logout(User $user): array
    {
        $user->token()->revoke();

        return [
            'message' => 'Logged out successfully.'
        ];
    }

    /**
     * Get current authenticated user with relations
     */
    public function getCurrentUser(User $user): User
    {
        return $user->load(['roles']);
    }

    /**
     * Get user status by WhatsApp number
     */
    public function getUserStatus(string $whatsappNumber): array
    {
        // Check for active user
        $activeUser = $this->userRepository->findActiveByWhatsapp($whatsappNumber);

        if ($activeUser) {
            return [
                'status' => 'active',
                'message' => 'Account is already activated',
                'user' => $activeUser,
                'can_login' => true,
                'can_register' => false,
            ];
        }

        // Check for any user with this WhatsApp number
        $user = $this->userRepository->findByWhatsapp($whatsappNumber);

        if ($user) {
            $isExpired = $this->isUnverifiedUserExpired($user);

            return [
                'status' => $isExpired ? 'expired' : 'inactive',
                'message' => $isExpired
                    ? 'Unverified account has expired'
                    : 'Unverified account exists for this number',
                'user' => $user,
                'created_at' => $user->created_at,
                'expired' => $isExpired,
                'can_update' => !$isExpired,
                'can_register' => $isExpired,
                'remaining_time' => !$isExpired ? $this->getRemainingVerificationTime($user) : null,
            ];
        }

        return [
            'status' => 'not_found',
            'message' => 'No account found for this number',
            'can_register' => true,
        ];
    }

    /**
     * Get remaining verification time for unverified user
     */
    public function getRemainingVerificationTime(User $user): array
    {
        if ($user->phone_verified_at) {
            return [
                'valid' => true,
                'message' => 'Account already verified',
                'remaining_minutes' => 0,
            ];
        }

        $accountAge = $user->created_at->diffInMinutes(now());
        $remainingMinutes = max(0, (self::UNVERIFIED_USER_EXPIRY_HOURS * 60) - $accountAge);

        return [
            'valid' => $remainingMinutes > 0,
            'message' => $remainingMinutes > 0
                ? "You have {$remainingMinutes} minutes to verify your account"
                : 'Verification period has expired',
            'remaining_minutes' => $remainingMinutes,
            'expires_at' => $user->created_at->addHours(self::UNVERIFIED_USER_EXPIRY_HOURS)->toDateTimeString(),
        ];
    }

    /**
     * ========== PRIVATE VALIDATION METHODS ==========
     */

    private function validateLoginCredentials(?User $user, string $password): void
    {
        if (!$user || !Hash::check($password, $user->password)) {
            // Increment login attempts
            if ($user) {
                $this->incrementLoginAttempts($user);
            }

            throw ValidationException::withMessages([
                'whatsapp_number' => 'Invalid credentials provided.'
            ]);
        }
    }

    private function validateUserVerification(User $user): void
    {
        if (!$user->phone_verified_at) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'Account is pending verification. Please verify your phone number first.'
            ]);
        }
    }

    private function validateUserActivation(User $user): void
    {
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'User account is inactive. Please contact support.'
            ]);
        }
    }

    private function validateLoginAttempts(User $user): void
    {
        $attempts = $user->login_attempts ?? 0;

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'Account locked due to too many failed login attempts. Please contact support.'
            ]);
        }
    }

    private function finalEmailValidation(User $user): void
    {
        // Check if another user has been activated with this email while this user was waiting
        $activeUserWithSameEmail = $this->userRepository->findActiveByEmail($user->email);

        if ($activeUserWithSameEmail && $activeUserWithSameEmail->id !== $user->id) {
            throw ValidationException::withMessages([
                'email' => 'This email has been registered with another active account. Please update your email.'
            ]);
        }
    }

    /**
     * ========== PRIVATE BUSINESS LOGIC METHODS ==========
     */

    private function updateExistingUser(User $user, array $data): array
    {
        // Validate email: allow same email for same user, block if used by different user
        $this->validateEmailForUpdate($user, $data['email']);

        // Prepare update data
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'updated_at' => now(),
        ];

        // Add role if provided
        if (isset($data['role'])) {
            $updateData['role'] = $data['role'];
        }

        // Update user data
        $this->userRepository->update($user, $updateData);

        // Update role if changed
        if (isset($data['role']) && $user->role->value !== $data['role']) {
            $user->syncRoles([$data['role']]);
        }

        // Clear old OTPs and send new one
        $this->otpService->deleteOldOTPs($user);
        $this->otpService->sendOTP($user);

        Log::info('Unverified user updated and OTP resent', [
            'user_id' => $user->id,
            'whatsapp' => $user->whatsapp_number,
        ]);

        return [
            'message' => 'Account data updated. New verification code sent.',
            'user_id' => $user->id,
            'is_new_user' => false,
        ];
    }

    private function createNewUser(array $data): array
    {
        // Validate email for new user (must be globally unique)
        $this->validateEmailForNewUser($data['email']);

        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? UserRole::PATIENT->value,
            'whatsapp_number' => $data['whatsapp_number'],
            'is_active' => false,
            'phone_verified_at' => null,
            'login_attempts' => 0,
        ];

        $user = $this->userRepository->create($userData);
        $user->assignRole($user->role->value);

        // Clear any old OTPs and send new one
        $this->otpService->deleteOldOTPs($user);
        $this->otpService->sendOTP($user);

        Log::info('New user registered', [
            'user_id' => $user->id,
            'whatsapp' => $user->whatsapp_number,
            'email' => $user->email,
        ]);

        return [
            'message' => 'Account created successfully. Please enter verification code.',
            'user_id' => $user->id,
            'is_new_user' => true,
            'verification_expires_in_hours' => self::UNVERIFIED_USER_EXPIRY_HOURS,
        ];
    }

    private function validateEmailForUpdate(User $user, string $newEmail): void
    {
        // If email hasn't changed, allow it (same user, same email)
        if ($user->email === $newEmail) {
            return;
        }

        // Check if new email is used by another user
        $existingUser = $this->userRepository->findByEmail($newEmail);

        // If found AND it's a different user, throw error
        if ($existingUser && $existingUser->id !== $user->id) {
            throw ValidationException::withMessages([
                'email' => 'Email is already registered with another account.'
            ]);
        }

        // Allow: either email not found, or found but it's the same user
        return;
    }

    private function validateEmailForNewUser(string $email): void
    {
        $existingUser = $this->userRepository->findByEmail($email);

        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => 'Email is already registered with another account.'
            ]);
        }
    }

    private function isUnverifiedUserExpired(User $user): bool
    {
        // If user is already verified, not expired
        if ($user->phone_verified_at || $user->is_active) {
            return false;
        }

        $accountAge = $user->created_at->diffInHours(now());
        return $accountAge >= self::UNVERIFIED_USER_EXPIRY_HOURS;
    }

    private function cleanupExpiredUnverifiedUser(string $whatsappNumber): void
    {
        $user = $this->userRepository->findByWhatsapp($whatsappNumber);

        if ($user && $this->isUnverifiedUserExpired($user)) {
            $this->userRepository->delete($user);
            Log::info('Expired unverified user cleaned up', [
                'whatsapp' => $whatsappNumber,
                'user_id' => $user->id,
            ]);
        }
    }

    private function activateUser(User $user): void
    {
        $this->userRepository->update($user, [
            'phone_verified_at' => now(),
            'is_active' => true,
            'login_attempts' => 0, // Reset login attempts on activation
        ]);

        Log::info('User account activated', [
            'user_id' => $user->id,
            'whatsapp' => $user->whatsapp_number,
        ]);
    }

    private function createAuthToken(User $user)
    {
        return $user->createToken('Personal Access Token');
    }

    private function incrementLoginAttempts(User $user): void
    {
        $attempts = ($user->login_attempts ?? 0) + 1;
        $this->userRepository->update($user, ['login_attempts' => $attempts]);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            Log::warning('Account locked due to too many failed login attempts', [
                'user_id' => $user->id,
                'attempts' => $attempts,
            ]);
        }
    }

    private function resetLoginAttempts(User $user): void
    {
        $this->userRepository->update($user, ['login_attempts' => 0]);
    }
    // أضف هذه الدوال في نهاية AuthService class

/**
 * Send password reset OTP
 */
public function forgotPassword(string $whatsappNumber): array
{
    $user = $this->userRepository->findByWhatsapp($whatsappNumber);

    if (!$user) {
        // For security, don't reveal if user exists
        return [
            'message' => 'If an account exists with this number, you will receive an OTP shortly.',
        ];
    }

    // Check if user is verified
    if (!$user->phone_verified_at) {
        throw ValidationException::withMessages([
            'account' => 'Account is not verified. Please verify your account first.'
        ]);
    }

    // Send OTP for password reset
    $this->otpService->deleteOldOTPs($user);
    $this->otpService->sendOTP($user);

    Log::info('Password reset OTP sent', [
        'user_id' => $user->id,
        'whatsapp' => $whatsappNumber,
    ]);

    return [
        'message' => 'Password reset OTP sent successfully.',
        'user_id' => $user->id,
    ];
}

/**
 * Reset password with OTP
 */
public function resetPassword(string $whatsappNumber, string $otp, string $newPassword): array
{
    $user = $this->userRepository->findByWhatsapp($whatsappNumber);

    if (!$user) {
        throw ValidationException::withMessages([
            'whatsapp_number' => 'WhatsApp number is not registered.'
        ]);
    }

    if ($this->otpService->verifyOTP($user, $otp)) {
        return DB::transaction(function () use ($user, $newPassword) {
            // Update password
            $this->userRepository->update($user, [
                'password' => Hash::make($newPassword),
                'login_attempts' => 0,
            ]);

            // Delete OTP after use
            $this->otpService->deleteOldOTPs($user);

            Log::info('Password reset successfully', [
                'user_id' => $user->id,
                'whatsapp' => $user->whatsapp_number,
            ]);

            return [
                'message' => 'Password reset successfully. You can now login with your new password.',
                'user' => $user,
            ];
        });
    }

    throw ValidationException::withMessages([
        'otp' => 'Invalid or expired verification code.'
    ]);
}

/**
 * Update user profile
 */
public function updateProfile(User $user, array $data): array
{
    $updateData = [];

    if (isset($data['name'])) {
        $updateData['name'] = $data['name'];
    }

    if (empty($updateData)) {
        throw ValidationException::withMessages([
            'data' => 'No valid data provided for update.'
        ]);
    }

    $this->userRepository->update($user, $updateData);
    $user->refresh();

    Log::info('User profile updated', [
        'user_id' => $user->id,
        'updated_fields' => array_keys($updateData),
    ]);

    return [
        'message' => 'Profile updated successfully.',
        'user' => $user,
    ];
}

/**
 * Change password
 */
public function changePassword(User $user, string $currentPassword, string $newPassword): array
{
    // Verify current password
    if (!Hash::check($currentPassword, $user->password)) {
        throw ValidationException::withMessages([
            'current_password' => 'Current password is incorrect.'
        ]);
    }

    // Update to new password
    $this->userRepository->update($user, [
        'password' => Hash::make($newPassword),
        'login_attempts' => 0,
    ]);

    Log::info('Password changed successfully', [
        'user_id' => $user->id,
    ]);

    return [
        'message' => 'Password changed successfully.',
    ];
}

/**
 * Get user active sessions
 */
public function getUserSessions(User $user): array
{
    return DB::table('oauth_access_tokens')
        ->where('user_id', $user->id)
        ->where('revoked', false)
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'scopes' => json_decode($token->scopes, true),
                'last_used' => $token->updated_at,
                'created_at' => $token->created_at,
                'expires_at' => $token->expires_at,
                'is_current' => request()->bearerToken() === $token->id,
            ];
        })
        ->toArray();
}

/**
 * Logout from all devices
 */
public function logoutAll(User $user): array
{
    $revoked = DB::table('oauth_access_tokens')
        ->where('user_id', $user->id)
        ->where('revoked', false)
        ->update(['revoked' => true]);

    Log::info('User logged out from all devices', [
        'user_id' => $user->id,
        'revoked_tokens' => $revoked,
    ]);

    return [
        'message' => 'Logged out from all devices successfully.',
        'revoked_count' => $revoked,
    ];
}

/**
 * Delete user account
 */
public function deleteAccount(User $user, string $password): array
{
    // Verify password
    if (!Hash::check($password, $user->password)) {
        throw ValidationException::withMessages([
            'password' => 'Password is incorrect.'
        ]);
    }

    // Revoke all tokens
    $this->logoutAll($user);

    // Delete user
    $this->userRepository->delete($user);

    Log::info('User account deleted', [
        'user_id' => $user->id,
    ]);

    return [
        'message' => 'Account deleted successfully.',
    ];
}
}
