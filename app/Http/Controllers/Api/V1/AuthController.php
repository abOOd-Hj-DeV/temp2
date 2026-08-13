<?php
// app/Http/Controllers/Api/V1/AuthController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\OTPRequest;
use App\Http\Requests\Api\V1\Auth\ResendOTPRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    /**
     * Register a new user (sends OTP, doesn't issue Token)
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'message' => $result['message'],
            'user_id' => $result['user_id'],
        ], 201);
    }

    /**
     * Verify OTP, activate account, and issue Token
     */
    public function verifyOTP(OTPRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->verifyOTP(
                $request->input('whatsapp_number'),
                $request->input('otp')
            );

            return response()->json([
                'message' => $result['message'],
                'user' => $result['user'],
                'access_token' => $result['token'],
                'token_type' => $result['token_type'],
                'expires_at' => $result['expires_at'],
            ]);

        } catch (ValidationException $e) {
            throw $e;
        }
    }

    /**
     * Resend OTP code
     */
    public function resendOTP(ResendOTPRequest $request): JsonResponse
    {
        try {
            $this->authService->resendOTP($request->input('whatsapp_number'));

            return response()->json([
                'message' => 'Verification code resent successfully.',
            ]);

        } catch (ValidationException $e) {
            throw $e;
        }
    }

    /**
     * User login (requires OTP verified account)
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return response()->json([
            'message' => 'Login successful.',
            'user' => $result['user'],
            'access_token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    /**
     * Logout user by revoking current access token
     *
     * @authenticated
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Get current user before logout
            $user = $request->user();
            $userId = $user ? $user->id : null;

            // Perform logout via AuthService
            $result = $this->authService->logout($user);

            // Log successful logout
            Log::info('User logged out successfully', [
                'user_id' => $userId,
                'logout_time' => now(),
            ]);

            return response()->json([
                'message' => $result['message'],
            ]);

        } catch (\Exception $e) {
            Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Logout failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get authenticated user information with relations
     *
     * @authenticated
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function user(Request $request): JsonResponse
    {
        try {
            // Get current authenticated user with relations
            $user = $this->authService->getCurrentUser($request->user());

            // Return user data (without sensitive information)
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'whatsapp_number' => $user->whatsapp_number,
                    'is_active' => $user->is_active,
                    'phone_verified_at' => $user->phone_verified_at,
                    'email_verified_at' => $user->email_verified_at,
                    'last_login' => $user->last_login,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'roles' => $user->roles->pluck('name'),
                   // 'profile' => $user->profile,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get user information', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Failed to retrieve user information.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Check user status by WhatsApp number (public endpoint)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $request->validate([
            'whatsapp_number' => 'required|string'
        ]);

        try {
            $status = $this->authService->getUserStatus($request->input('whatsapp_number'));

            return response()->json($status);

        } catch (\Exception $e) {
            Log::error('Failed to check user status', [
                'error' => $e->getMessage(),
                'whatsapp' => $request->input('whatsapp_number'),
            ]);

            return response()->json([
                'message' => 'Failed to check user status.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    // أضف هذه الدوال في نهاية AuthController class

/**
 * Send password reset OTP
 */
public function forgotPassword(Request $request): JsonResponse
{
    $request->validate([
        'whatsapp_number' => 'required|string'
    ]);

    try {
        $result = $this->authService->forgotPassword($request->input('whatsapp_number'));

        return response()->json([
            'message' => $result['message'],
            'user_id' => $result['user_id'] ?? null,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to send password reset OTP', [
            'error' => $e->getMessage(),
            'whatsapp' => $request->input('whatsapp_number'),
        ]);

        return response()->json([
            'message' => 'Failed to process password reset request.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Reset password with OTP
 */
public function resetPassword(Request $request): JsonResponse
{
    $request->validate([
        'whatsapp_number' => 'required|string',
        'otp' => 'required|string',
        'new_password' => 'required|string|min:8|confirmed',
    ]);

    try {
        $result = $this->authService->resetPassword(
            $request->input('whatsapp_number'),
            $request->input('otp'),
            $request->input('new_password')
        );

        return response()->json([
            'message' => $result['message'],
            'user' => $result['user'],
        ]);

    } catch (ValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        Log::error('Failed to reset password', [
            'error' => $e->getMessage(),
            'whatsapp' => $request->input('whatsapp_number'),
        ]);

        return response()->json([
            'message' => 'Failed to reset password.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Update user profile
 *
 * @authenticated
 */
public function updateProfile(Request $request): JsonResponse
{
    $request->validate([
        'name' => 'sometimes|string|max:255',
    ]);

    try {
        $result = $this->authService->updateProfile(
            $request->user(),
            $request->only(['name'])
        );

        return response()->json([
            'message' => $result['message'],
            'user' => $result['user'],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to update profile', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to update profile.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Change password
 *
 * @authenticated
 */
public function changePassword(Request $request): JsonResponse
{
    $request->validate([
        'current_password' => 'required|string',
        'new_password' => 'required|string|min:8|confirmed',
    ]);

    try {
        $result = $this->authService->changePassword(
            $request->user(),
            $request->input('current_password'),
            $request->input('new_password')
        );

        return response()->json([
            'message' => $result['message'],
        ]);

    } catch (ValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        Log::error('Failed to change password', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to change password.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Update email address
 *
 * @authenticated
 */
public function updateEmail(Request $request): JsonResponse
{
    $request->validate([
        'email' => 'required|email|max:255|unique:users,email,' . $request->user()->id,
        'password' => 'required|string',
    ]);

    try {
        $result = $this->authService->updateEmail(
            $request->user(),
            $request->input('email'),
            $request->input('password')
        );

        return response()->json([
            'message' => $result['message'],
            'user' => $result['user'],
        ]);

    } catch (ValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        Log::error('Failed to update email', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to update email.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Get active sessions
 *
 * @authenticated
 */
public function getSessions(Request $request): JsonResponse
{
    try {
        $sessions = $this->authService->getUserSessions($request->user());

        return response()->json([
            'sessions' => $sessions,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to get user sessions', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to retrieve sessions.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Logout from all devices
 *
 * @authenticated
 */
public function logoutAll(Request $request): JsonResponse
{
    try {
        $result = $this->authService->logoutAll($request->user());

        return response()->json([
            'message' => $result['message'],
            'revoked_count' => $result['revoked_count'],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to logout from all devices', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to logout from all devices.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Delete user account
 *
 * @authenticated
 */
public function deleteAccount(Request $request): JsonResponse
{
    $request->validate([
        'password' => 'required|string',
    ]);

    try {
        $result = $this->authService->deleteAccount(
            $request->user(),
            $request->input('password')
        );

        return response()->json([
            'message' => $result['message'],
        ]);

    } catch (ValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        Log::error('Failed to delete account', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Failed to delete account.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
}
