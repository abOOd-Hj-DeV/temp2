<?php

namespace App\Services\Therapist;

use App\Enums\ApprovalStatus;
use App\Models\Therapist;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TherapistService
{
    /**
     * Fields a therapist may edit on their own profile.
     * Approval status, rating and client counters stay system-owned.
     */
    private const EDITABLE_FIELDS = ['specialty', 'country', 'languages', 'bio', 'availability', 'clients_limit'];

    public function __construct(
        private TherapistRepositoryInterface $therapists,
        private SessionRepositoryInterface $sessions,
        private NotificationService $notifications,
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

    public function dashboard(Therapist $therapist): array
    {
        $upcoming = $this->sessions->forTherapist($therapist->user_id, 10);

        return [
            'therapist' => $this->toArray($therapist),
            'clients_count' => $therapist->clients_count,
            'clients_limit' => $therapist->clients_limit,
            'can_accept_clients' => $therapist->can_accept_new_clients,
            'upcoming_sessions' => $upcoming->items(),
        ];
    }

    public function toArray(Therapist $therapist): array
    {
        return [
            'id' => $therapist->user_id,
            'full_name' => $therapist->full_name,
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
