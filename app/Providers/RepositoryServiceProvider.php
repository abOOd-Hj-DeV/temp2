<?php
// app/Providers/RepositoryServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\UserRepository;
use App\Services\UltraMsg\UltraMsgService;
use App\Services\Auth\OTPService;
use App\Services\Auth\AuthService;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Repositories\Eloquent\PatientRepository;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Eloquent\AssessmentRepository;
use App\Repositories\Contracts\RedFlagRepositoryInterface;
use App\Repositories\Eloquent\RedFlagRepository;
use App\Services\Assessment\AssessmentService;
use App\Services\RedFlagService;
use App\Services\NotificationService;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Eloquent\SessionRepository;
use App\Repositories\Contracts\ProgramRepositoryInterface;
use App\Repositories\Eloquent\ProgramRepository;
use App\Repositories\Contracts\ModuleRepositoryInterface;
use App\Repositories\Eloquent\ModuleRepository;
use App\Repositories\Contracts\PatientModuleRepositoryInterface;
use App\Repositories\Eloquent\PatientModuleRepository;
use App\Services\Patient\PatientHelperService;
use App\Services\Patient\PatientProfileService;
use App\Services\Patient\PatientDashboardService;
use App\Services\Patient\PatientOnboardingService;
use App\Services\Patient\PatientAppointmentService;
use App\Services\Patient\PatientProgramService;
use App\Services\Patient\PatientAccountService;
use App\Services\Patient\PatientExportService;
use App\Services\Patient\PatientProgressService;
use App\Services\Patient\PatientService;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ============================================
        // 1. ربط الـ Repositories
        // ============================================
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(PatientRepositoryInterface::class, PatientRepository::class);
        $this->app->bind(AssessmentRepositoryInterface::class, AssessmentRepository::class);
        $this->app->bind(RedFlagRepositoryInterface::class, RedFlagRepository::class);
        $this->app->bind(SessionRepositoryInterface::class, SessionRepository::class);
        $this->app->bind(ProgramRepositoryInterface::class, ProgramRepository::class);
        $this->app->bind(ModuleRepositoryInterface::class, ModuleRepository::class);
        $this->app->bind(PatientModuleRepositoryInterface::class, PatientModuleRepository::class);

        // ============================================
        // 2. ربط الخدمات المساعدة
        // ============================================
        $this->app->bind(NotificationService::class, function ($app) {
            return new NotificationService();
        });

        // ============================================
        // 3. ربط UltraMsgService
        // ============================================
        $this->app->bind(UltraMsgService::class, function ($app) {
            return new UltraMsgService();
        });

        // ============================================
        // 4. ربط OTPService
        // ============================================
        $this->app->bind(OTPService::class, function ($app) {
            return new OTPService($app->make(UltraMsgService::class));
        });

        // ============================================
        // 5. ربط AuthService
        // ============================================
        $this->app->bind(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(UserRepositoryInterface::class),
                $app->make(OTPService::class)
            );
        });

        // ============================================
        // 6. ربط RedFlagService
        // ============================================
        $this->app->bind(RedFlagService::class, function ($app) {
            return new RedFlagService(
                $app->make(RedFlagRepositoryInterface::class),
                $app->make(NotificationService::class)
            );
        });

        // ============================================
        // 7. ربط AssessmentService
        // ============================================
        $this->app->bind(AssessmentService::class, function ($app) {
            return new AssessmentService(
                $app->make(AssessmentRepositoryInterface::class),
                $app->make(PatientRepositoryInterface::class),
                $app->make(NotificationService::class),
                $app->make(RedFlagService::class)
            );
        });

        // ============================================
        // 8. ربط خدمات الـ Patient الجديدة
        // ============================================

        // PatientHelperService (Singleton)
        $this->app->singleton(PatientHelperService::class);

        // PatientProfileService
        $this->app->bind(PatientProfileService::class, function ($app) {
            return new PatientProfileService(
                $app->make(PatientRepositoryInterface::class),
                $app->make(PatientHelperService::class)
            );
        });

        // PatientDashboardService
        $this->app->bind(PatientDashboardService::class, function ($app) {
            return new PatientDashboardService(
                $app->make(PatientRepositoryInterface::class),
                $app->make(PatientHelperService::class)
            );
        });

        // PatientOnboardingService
        $this->app->bind(PatientOnboardingService::class, function ($app) {
            return new PatientOnboardingService(
                $app->make(PatientRepositoryInterface::class)
            );
        });

        // PatientAppointmentService
        $this->app->bind(PatientAppointmentService::class, function ($app) {
            return new PatientAppointmentService(
                $app->make(PatientRepositoryInterface::class)
            );
        });

        // PatientProgramService
        $this->app->bind(PatientProgramService::class, function ($app) {
            return new PatientProgramService(
                $app->make(PatientRepositoryInterface::class)
            );
        });

        // PatientAccountService
        $this->app->bind(PatientAccountService::class, function ($app) {
            return new PatientAccountService(
                $app->make(PatientRepositoryInterface::class)
            );
        });

        // PatientExportService
        $this->app->bind(PatientExportService::class, function ($app) {
            return new PatientExportService(
                $app->make(PatientRepositoryInterface::class)
            );
        });

        // PatientProgressService
        $this->app->bind(PatientProgressService::class, function ($app) {
            return new PatientProgressService(
                $app->make(PatientRepositoryInterface::class),
                $app->make(PatientHelperService::class)
            );
        });

        // PatientService الرئيسي
        $this->app->bind(PatientService::class, function ($app) {
            return new PatientService(
                $app->make(PatientProfileService::class),
                $app->make(PatientDashboardService::class),
                $app->make(PatientOnboardingService::class),
                $app->make(PatientAppointmentService::class),
                $app->make(PatientProgramService::class),
                $app->make(PatientAccountService::class),
                $app->make(PatientExportService::class),
                $app->make(PatientProgressService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
