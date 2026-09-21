<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\StaffAccountService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Management-side creation and lifecycle of staff and therapist accounts.
 * Patients are never managed here.
 */
class UserAccountController extends Controller
{
    public function __construct(private StaffAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(array_diff(UserRole::values(), [UserRole::PATIENT->value]))],
            'is_active' => 'nullable|boolean',
            'pending_activation' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $this->accounts->paginate($request->user(), $filters, (int) ($filters['per_page'] ?? 20))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('whatsapp_number'))) {
            $request->merge(['whatsapp_number' => PhoneNumber::normalize($request->input('whatsapp_number')) ?? $request->input('whatsapp_number')]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'whatsapp_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'role' => ['required', Rule::in(StaffAccountService::creatableRolesFor($request->user()))],
            'therapist' => ['required_if:role,therapist', 'array'],
            'therapist.full_name' => 'nullable|string|max:255',
            'therapist.specialty' => 'required_if:role,therapist|string|max:120',
            'therapist.country' => 'required_if:role,therapist|string|max:80',
            'therapist.languages' => 'nullable|array|max:10',
            'therapist.languages.*' => 'string|max:10',
            'therapist.bio' => 'nullable|string|max:5000',
            'therapist.clients_limit' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $this->accounts->invite($request->user(), $data);

        return response()->json([
            'message' => 'Account created; an activation code was sent to the WhatsApp number.',
            'user' => $this->accounts->toArray($result['user']->load('therapist')),
            'invitation_expires_at' => $result['expires_at']->toISOString(),
        ], 201);
    }

    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $result = $this->accounts->resendInvitation($request->user(), $user);

        return response()->json([
            'message' => 'A new activation code was sent.',
            'invitation_expires_at' => $result['expires_at']->toISOString(),
        ]);
    }

    public function setActive(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $user = $this->accounts->setActive($request->user(), $user, (bool) $data['is_active']);

        return response()->json([
            'message' => $user->is_active ? 'Account reactivated.' : 'Account deactivated.',
            'user' => $this->accounts->toArray($user->load('therapist')),
        ]);
    }
}
