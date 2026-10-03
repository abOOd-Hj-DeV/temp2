<?php

namespace App\Console\Commands;

use App\Models\ClinicalNotificationEvent;
use App\Services\Patient\ClinicalEventDelivery;
use Illuminate\Console\Command;

class DeliverClinicalNotifications extends Command
{
    protected $signature = 'clinical:deliver-notifications';

    protected $description = 'Retry committed clinical in-app notifications';

    public function handle(ClinicalEventDelivery $delivery): int
    {
        ClinicalNotificationEvent::whereNull('delivered_at')->eachById(function ($event) use ($delivery) {
            try {
                $delivery->deliver($event->id);
            } catch (\Throwable $e) {
                report($e);
            }
        });
        $pending = ClinicalNotificationEvent::whereNull('delivered_at')->count();
        if ($pending > 0) {
            $this->error("{$pending} clinical notification events remain pending; check eligible staff and delivery configuration.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
