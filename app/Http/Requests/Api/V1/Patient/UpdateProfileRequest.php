<?php

namespace App\Http\Requests\Api\V1\Patient;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only self-reported demographics are accepted here. Clinical fields
     * (assessment_score, safety_flag, compliance_level, therapist_id,
     * subscription_id) are rejected outright.
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'age' => ['sometimes', 'required', 'integer', 'min:18', 'max:120'],
            'gender' => ['sometimes', 'required', 'in:male,female,other'],
            'language' => ['sometimes', 'required', 'string', 'in:ar,en'],
        ];
    }
}
