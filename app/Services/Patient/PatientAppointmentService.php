<?php
// app/Services/Patient/PatientAppointmentService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Session;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientAppointmentService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository
    ) {}

    public function getAppointments(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_profile' => false,
                    'message' => 'Please complete your profile to view appointments',
                    'appointments' => []
                ];
            }

            $upcomingSessions = Session::where('patient_id', $patient->user_id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('session_date', '>=', now()->toDateString())
                ->orderBy('session_date')
                ->orderBy('session_time')
                ->get()
                ->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'therapist_id' => $session->therapist_id,
                        'session_date' => $session->session_date,
                        'session_time' => $session->session_time,
                        'medium' => $session->medium,
                        'status' => $session->status,
                        'link' => $session->link,
                        'is_initial' => (bool) $session->is_initial,
                        'payment_status' => $session->payment_status,
                        'price' => $session->price
                    ];
                });

            $pastSessions = Session::where('patient_id', $patient->user_id)
                ->whereIn('status', ['completed', 'cancelled'])
                ->where('session_date', '<', now()->toDateString())
                ->orderBy('session_date', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'therapist_id' => $session->therapist_id,
                        'session_date' => $session->session_date,
                        'session_time' => $session->session_time,
                        'medium' => $session->medium,
                        'status' => $session->status,
                        'summary' => $session->summary
                    ];
                });

            return [
                'has_profile' => true,
                'upcoming' => [
                    'count' => $upcomingSessions->count(),
                    'sessions' => $upcomingSessions
                ],
                'past' => [
                    'count' => $pastSessions->count(),
                    'sessions' => $pastSessions
                ],
                'stats' => [
                    'total_sessions' => Session::where('patient_id', $patient->user_id)->count(),
                    'completed_sessions' => Session::where('patient_id', $patient->user_id)
                        ->where('status', 'completed')->count(),
                    'upcoming_count' => $upcomingSessions->count(),
                    'cancelled_count' => Session::where('patient_id', $patient->user_id)
                        ->where('status', 'cancelled')->count()
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get patient appointments', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
