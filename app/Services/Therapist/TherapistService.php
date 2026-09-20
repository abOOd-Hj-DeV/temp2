<?php

namespace App\Services\Therapist;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Therapist;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TherapistService
{
    /**
     * Fields a therapist may edit on their own profile. Approval status,
     * rating, client counters and clients_limit stay admin/system-owned.
     */
    private const EDITABLE_FIELDS = ['specialty', 'country', 'languages', 'bio', 'availability'];

    public function __construct(
        private TherapistRepositoryInterface $therapists,
        private SessionRepositoryInterface $sessions,
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    public function listTherapists(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 50));

        return $this->therapists->listApproved($filters, $perPage);
    }

    public function getTherapist(string $userId): Therapist
    {
        $therapist = $this->therapists->findByUserId($userId);

        if (! $therapist || $therapist->approval_status !== ApprovalStatus::APPROVED) {
            throw ValidationException::withMessages(['therapist_id' => 'Therapist not found.']);
        }

        return $therapist;
    }

    /**
     * Bookable start times for a therapist on a date, derived from the
     * availability windows minus already-booked and past slots.
     *
     * @return array<int, string> e.g. ['09:00','10:00']
     */
    public function availableSlots(Therapist $therapist, Carbon $date): array
    {
        $day = strtolower($date->format('l'));
        $windows = $therapist->availability[$day] ?? [];

        if (! is_array($windows)) {
            return [];
        }

        $duration = (int) config('sakina.session_duration_minutes', 60);
        $booked = $this->sessions->bookedTimesFor($therapist->user_id, $date->toDateString());
        $now = now();
        $slots = [];

        foreach ($windows as $window) {
            if (! is_string($window) || ! preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $window, $m)) {
                continue;
            }

            $start = $date->copy()->setTime((int) $m[1], (int) $m[2]);
            $end = $date->copy()->setTime((int) $m[3], (int) $m[4]);

            for ($slot = $start->copy(); $slot->copy()->addMinutes($duration)->lte($end); $slot->addMinutes($duration)) {
                $time = $slot->format('H:i');

                if ($slot->lte($now) || in_array($time, $booked, true)) {
                    continue;
                }

                $slots[] = $time;
            }
        }

        sort($slots);

        return $slots;
    }

    public function isSlotAvailable(Therapist $therapist, Carbon $date, string $time): bool
    {
        return in_array(substr($time, 0, 5), $this->availableSlots($therapist, $date), true);
    }

    /**
     * Therapist self-service settings update — whitelisted fields only.
     */
    public function updateSettings(Therapist $therapist, array $data): Therapist
    {
        $update = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        if (isset($update['availability'])) {
            $update['availability'] = $this->validateAvailability($update['availability']);
        }

        $this->therapists->update($therapist, $update);

        return $therapist->refresh();
    }

    /**
     * Shape check for {"monday": ["09:00-12:00"], ...} — throws 422 on any
     * malformed window so a bad availability block can never silently
     * erase a therapist's bookable hours.
     */
    public function validateAvailability(mixed $availability): array
    {
        if (! is_array($availability)) {
            throw ValidationException::withMessages(['availability' => 'Availability must be an object of weekday windows.']);
        }

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        foreach ($availability as $day => $windows) {
            if (! in_array($day, $days, true) || ! is_array($windows)) {
                throw ValidationException::withMessages(['availability' => "Invalid availability day: {$day}."]);
            }

            foreach ($windows as $window) {
                if (! is_string($window) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $window)) {
                    throw ValidationException::withMessages(['availability' => "Invalid window '{$window}' — expected HH:MM-HH:MM."]);
                }

                [$start, $end] = explode('-', $window);

                if ($end <= $start) {
                    throw ValidationException::withMessages(['availability' => "Window '{$window}' ends before it starts."]);
                }
            }

            $sorted = array_values($windows);
            sort($sorted);

            for ($i = 1; $i < count($sorted); $i++) {
                if (substr($sorted[$i], 0, 5) < substr($sorted[$i - 1], 6)) {
                    throw ValidationException::withMessages(['availability' => "Windows overlap on {$day}."]);
                }
            }
        }

        return $availability;
    }

    /**
     * Therapist submits their license for admin review. Status returns to
     * pending until an admin decides.
     */
    public function submitForApproval(Therapist $therapist, ?UploadedFile $license = null): Therapist
    {
        if (! $license && ! $therapist->license_file_path) {
            throw ValidationException::withMessages(['license' => 'A license file is required before submitting for approval.']);
        }

        $data = ['approval_status' => ApprovalStatus::PENDING->value];

        if ($license) {
            $data['license_file_path'] = $license->store(
                "licenses/{$therapist->user_id}",
                ['disk' => config('sakina.uploads_disk', 'local')]
            );
        }

        $this->therapists->update($therapist, $data);

        return $therapist->refresh();
    }

    /**
     * Admin approves/rejects a therapist. Approval requires a license on
     * file; the row is locked so two admins cannot race each other.
     */
    public function decideApproval(string $therapistUserId, ApprovalStatus $status, User $admin, ?string $note = null): Therapist
    {
        $therapist = DB::transaction(function () use ($therapistUserId, $status, $admin, $note) {
            $therapist = Therapist::whereKey($therapistUserId)->lockForUpdate()->first();

            if (! $therapist) {
                throw ValidationException::withMessages(['therapist' => 'Therapist not found.']);
            }

            // pending → approved | rejected is the only legal transition: a second
            // reviewer racing on the same application sees 409, not a flip.
            if ($therapist->approval_status === null) {
                throw ValidationException::withMessages(['therapist' => 'Therapist has not submitted an approval request.']);
            }

            if ($therapist->approval_status !== ApprovalStatus::PENDING) {
                throw new ConflictException("Therapist is already {$therapist->approval_status->value}.");
            }

            if ($status === ApprovalStatus::APPROVED && ! $therapist->license_file_path) {
                throw ValidationException::withMessages(['therapist' => 'Cannot approve a therapist without a license file.']);
            }

            $this->therapists->update($therapist, ['approval_status' => $status->value]);

            $this->audit->record($admin, AuditLogService::THERAPIST_APPROVAL_DECIDED, $therapist->user_id, [
                'status' => $status->value,
                'note' => $note,
            ]);

            return $therapist->refresh();
        });

        $this->notifications->therapistApprovalDecided($therapist);

        return $therapist;
    }

    /** Admin-only: change how many distinct clients a therapist may carry. */
    public function updateClientsLimit(Therapist $therapist, int $limit, User $admin): Therapist
    {
        $previous = $therapist->clients_limit;
        $this->therapists->update($therapist, ['clients_limit' => $limit]);

        $this->audit->record($admin, AuditLogService::THERAPIST_LIMIT_CHANGED, $therapist->user_id, [
            'from' => $previous,
            'to' => $limit,
        ]);

        return $therapist->refresh();
    }

    public function dashboard(Therapist $therapist): array
    {
        $today = now()->toDateString();
        $base = $therapist->sessions();

        return [
            'therapist' => $this->toArray($therapist),
            'clients_count' => $therapist->clients_count,
            'clients_limit' => $therapist->clients_limit,
            'can_accept_clients' => $therapist->can_accept_new_clients,
            'stats' => [
                'pending' => (clone $base)->where('status', SessionStatus::PENDING->value)->count(),
                'confirmed' => (clone $base)->where('status', SessionStatus::CONFIRMED->value)->count(),
                'completed' => (clone $base)->where('status', SessionStatus::COMPLETED->value)->count(),
                'today' => (clone $base)->whereDate('session_date', $today)
                    ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])->count(),
            ],
            'upcoming_sessions' => (clone $base)
                ->whereDate('session_date', '>=', $today)
                ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])
                ->orderBy('session_date')->orderBy('session_time')
                ->limit(10)
                ->get()
                ->all(),
        ];
    }

    public function toArray(Therapist $therapist): array
    {
        return [
            'id' => $therapist->user_id,
            'full_name' => $therapist->full_name,
            'has_license' => $therapist->license_file_path !== null,
            'specialty' => $therapist->specialty,
            'country' => $therapist->country,
            'languages' => $therapist->languages,
            'bio' => $therapist->bio,
            'rating' => $therapist->rating,
            'approval_status' => $therapist->approval_status?->value,
            'can_accept_new_clients' => $therapist->can_accept_new_clients,
            'availability' => $therapist->availability,
        ];
    }
}
