<?php

namespace App\Http\Requests\Api\V1\Session;

use Illuminate\Foundation\Http\FormRequest;

class CompleteSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'summary' => 'nullable|string|max:5000',
        ];
    }
}
