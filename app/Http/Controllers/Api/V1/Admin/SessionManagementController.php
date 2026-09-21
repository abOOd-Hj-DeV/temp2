<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use App\Services\AuditLogService;
use App\Services\Session\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionManagementController extends Controller
{
    public function __construct(
        private SessionService $sessions,
        private AuditLogService $audit,
    ) {}

    /**
     * Clinical staff cancel a pending/confirmed session on behalf of either
     * participant (no notice-period restriction; reason is audited).
     */
    public function cancel(Request $request, TherapySession $session): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $session = $this->sessions->cancel($session, $request->user());

        $this->audit->record($request->user(), AuditLogService::SESSION_CANCELLED_BY_STAFF, $session->id, [
            'reason' => $data['reason'],
            'patient_id' => $session->patient_id,
            'therapist_id' => $session->therapist_id,
        ]);

        return response()->json([
            'message' => 'Session cancelled.',
            'session' => $this->sessions->toArray($session, $request->user()),
        ]);
    }
}
