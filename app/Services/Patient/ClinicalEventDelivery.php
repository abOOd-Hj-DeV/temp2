<?php

namespace App\Services\Patient;

use App\Models\ClinicalNotificationEvent;
use App\Models\User;
use App\Notifications\ClinicalUpdateNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\RedFlagService;
use Illuminate\Support\Facades\DB;

class ClinicalEventDelivery
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function record(string $patientId, string $kind, string $entityId): ClinicalNotificationEvent
    {
        $event = ClinicalNotificationEvent::create(['patient_id' => $patientId, 'kind' => $kind, 'entity_id' => $entityId]);
        DB::afterCommit(function () use ($event) {
            try {
                $this->deliver($event->id);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $event;
    }

    public function deliver(string $eventId): void
    {
        DB::transaction(function () use ($eventId) {
            $patientId = ClinicalNotificationEvent::whereKey($eventId)->value('patient_id');
            $owner = $patientId ? User::whereKey($patientId)->lockForUpdate()->first() : null;
            $event = ClinicalNotificationEvent::whereKey($eventId)->lockForUpdate()->first();
            if (! $event || $event->delivered_at) {
                return;
            }
            if (! $owner || $owner->anonymized_at || ! $owner->is_active) {
                return;
            }
            $recipients = $event->kind === 'parallel_layer_updated'
                ? collect([$owner])
                : User::where('is_active', true)->whereIn('role', array_map(fn ($role) => $role->value, RedFlagService::CLINICAL_STAFF_ROLES))->get();
            if ($recipients->isEmpty()) {
                return;
            }
            foreach ($recipients as $user) {
                $this->dispatcher->inApp($user, new ClinicalUpdateNotification($event->kind, $event->entity_id, $event->id), "{$event->kind}:{$event->id}");
            }
            $event->update(['delivered_at' => now()]);
        });
    }
}
