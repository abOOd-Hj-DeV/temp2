<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Therapist;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Incomplete onboarding must never reach the services as null and surface
     * as a 500; it is a 422 the client can act on.
     */
    protected function patientOf(Request $request): Patient
    {
        return $request->user()?->patient
            ?? throw ValidationException::withMessages([
                'patient' => __('Complete your patient profile first.'),
            ]);
    }

    protected function therapistOf(Request $request): Therapist
    {
        return $request->user()?->therapist
            ?? throw ValidationException::withMessages([
                'therapist' => __('Complete your therapist profile first.'),
            ]);
    }

    /** Single upper bound for every paginated endpoint. */
    protected function perPage(Request $request, int $default = 15): int
    {
        $max = (int) config('sakina.max_per_page', 100);
        $value = (int) $request->input('per_page', $default);

        return max(1, min($value, $max));
    }
}
