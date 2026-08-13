<?php
// app/Http/Middleware/CheckUserStatus.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check if user is active and phone verified
        if (!$user->is_active || !$user->phone_verified_at) {
            return response()->json([
                'message' => 'Account is not active. Please verify your phone number first.'
            ], 403);
        }

        return $next($request);
    }
}
