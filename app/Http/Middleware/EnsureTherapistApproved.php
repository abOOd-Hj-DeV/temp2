<?php

namespace App\Http\Middleware;

use App\Enums\ApprovalStatus;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks therapist-only operational routes (sessions, dashboard, clients,
 * wallet) until an admin has approved the profile. Onboarding routes
 * (settings, approval submission) are intentionally left open.
 */
class EnsureTherapistApproved
{
    public function handle(Request $request, Closure $next)
    {
        $therapist = $request->user()?->therapist;

        if (! $therapist || $therapist->approval_status !== ApprovalStatus::APPROVED) {
            return response()->json([
                'message' => 'Your therapist profile has not been approved yet.',
            ], 403);
        }

        return $next($request);
    }
}
