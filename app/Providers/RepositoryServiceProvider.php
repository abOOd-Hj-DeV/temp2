<?php

namespace App\Providers;

use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\RedFlagRepositoryInterface;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\AssessmentRepository;
use App\Repositories\Eloquent\PatientRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\RedFlagRepository;
use App\Repositories\Eloquent\SessionRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Repositories\Eloquent\TherapistRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Services\Messaging\DisabledWhatsAppSender;
use App\Services\Messaging\LogWhatsAppSender;
use App\Services\Messaging\UltraMsgWhatsAppSender;
use App\Services\Messaging\WhatsAppSenderInterface;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(PatientRepositoryInterface::class, PatientRepository::class);
        $this->app->bind(AssessmentRepositoryInterface::class, AssessmentRepository::class);
        $this->app->bind(RedFlagRepositoryInterface::class, RedFlagRepository::class);
        $this->app->bind(TherapistRepositoryInterface::class, TherapistRepository::class);
        $this->app->bind(SessionRepositoryInterface::class, SessionRepository::class);
        $this->app->bind(SubscriptionRepositoryInterface::class, SubscriptionRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);

        // UltraMsg in production, log-only fallback when not configured.
        $this->app->bind(WhatsAppSenderInterface::class, function () {
            $config = config('services.ultramsg');

            if (! empty($config['instance_id']) && ! empty($config['token'])) {
                return new UltraMsgWhatsAppSender(
                    new Client,
                    $config['instance_id'],
                    $config['token'],
                    $config['base_url'] ?? 'https://api.ultramsg.com'
                );
            }

            return $this->app->environment('production')
                ? new DisabledWhatsAppSender
                : new LogWhatsAppSender;
        });
    }
}
