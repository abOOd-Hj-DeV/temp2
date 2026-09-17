<?php

namespace App\Http\Requests\Api\V1\Assessment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:phq9,gad7'],
            'answers' => ['required', 'array'],
            'answers.*' => ['integer', 'min:0', 'max:3'],
        ];
    }
}
