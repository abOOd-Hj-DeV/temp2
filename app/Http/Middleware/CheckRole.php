<?php

// app/Http/Middleware/CheckRole.php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // users.role is the source of truth; Spatie assignments are derived
        // from it and must never grant access on their own.
        $actual = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if (in_array($actual, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Unauthorized. Required role(s): '.implode(', ', $roles),
        ], 403);
    }
}
