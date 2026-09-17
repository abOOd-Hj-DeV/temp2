<?php

namespace App\Http\Requests\Api\V1\Therapist;

use Illuminate\Foundation\Http\FormRequest;

class SubmitApprovalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'license' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
