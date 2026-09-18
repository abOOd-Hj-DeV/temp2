<?php

namespace App\Http\Requests\Api\V1\Therapist;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTherapistSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'specialty' => 'sometimes|string|max:255',
            'country' => 'sometimes|string|max:100',
            'languages' => 'sometimes|array',
            'languages.*' => 'string|max:10',
            'bio' => 'nullable|string|max:2000',
            'availability' => 'sometimes|array',
            'clients_limit' => 'prohibited',
        ];
    }
}
