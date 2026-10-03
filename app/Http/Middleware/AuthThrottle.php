<?php

namespace App\Http\Middleware;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;

class AuthThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $action = (string) $request->route()->getName();
        // The send/resend aliases share one budget, not separate ways around it.
        $action = $action === 'auth.otp.send' ? 'auth.otp.resend' : $action;
        $name = 'auth-security:'.$action;

        RateLimiter::for($name, function () use ($request, $maxAttempts) {
            $limits = [Limit::perMinute((int) $maxAttempts)->by('ip:'.$request->ip())];
            $number = $request->input('whatsapp_number');
            if (is_string($number) && ($normalized = PhoneNumber::normalize($number))) {
                $limits[] = Limit::perMinute((int) $maxAttempts)->by('phone:'.hash_hmac('sha256', $normalized, (string) config('app.key')));
            }
            $challenge = $request->input('login_challenge');
            if (is_string($challenge) && $challenge !== '') {
                $limits[] = Limit::perMinute((int) $maxAttempts)->by('challenge:'.hash('sha256', $challenge));
            }

            return $limits;
        });

        return $this->handleRequestUsingNamedLimiter($request, $next, $name, $this->limiter->limiter($name));
    }
}
