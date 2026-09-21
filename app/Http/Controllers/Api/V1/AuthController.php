<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ActivateAccountRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\OTPRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendOTPRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json($this->auth->register($request->validated()), 201);
    }

    public function verifyOTP(OTPRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->verifyOtp(
                $request->input('whatsapp_number'),
                $request->input('otp')
            )
        );
    }

    public function resendOTP(ResendOTPRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->resendOtp($request->input('whatsapp_number'))
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->auth->login($request->validated()));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->forgotPassword($request->input('whatsapp_number'))
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->resetPassword(
                $request->input('whatsapp_number'),
                $request->input('otp'),
                $request->input('password')
            )
        );
    }

    public function activate(ActivateAccountRequest $request): JsonResponse
    {
        return response()->json(
            $this->auth->activate(
                $request->input('whatsapp_number'),
                $request->input('code'),
                $request->input('password')
            )
        );
    }

    public function checkStatus(Request $request): JsonResponse
    {
        $request->validate(['whatsapp_number' => ['required', 'string']]);

        return response()->json(
            $this->auth->checkStatus($request->input('whatsapp_number'))
        );
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->auth->currentUser($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        return response()->json($this->auth->logout($request->user()));
    }

    public function logoutAll(Request $request): JsonResponse
    {
        return response()->json($this->auth->logoutAll($request->user()));
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        return response()->json($this->auth->refresh($request->validated('refresh_token')));
    }
}
