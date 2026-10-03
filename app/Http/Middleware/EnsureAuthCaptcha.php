<?php

namespace App\Http\Middleware;

use App\Services\Auth\CaptchaVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class EnsureAuthCaptcha
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment('production') && ! config('auth_security.captcha.enabled')) {
            return $next($request);
        }

        $class = config('auth_security.captcha.verifier');
        if (! is_string($class) || ! is_a($class, CaptchaVerifier::class, true)) {
            throw new ServiceUnavailableHttpException(null, 'Authentication abuse protection is not configured.');
        }

        $token = $request->input('captcha_token');
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            throw ValidationException::withMessages(['captcha_token' => __('A valid CAPTCHA is required.')]);
        }

        try {
            $valid = app($class)->verify($token, (string) $request->ip(), (string) $request->route()->getName());
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Authentication abuse protection is temporarily unavailable.');
        }

        if (! $valid) {
            throw ValidationException::withMessages(['captcha_token' => __('CAPTCHA verification failed.')]);
        }

        return $next($request);
    }
}
