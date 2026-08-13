<?php
// app/Http/Requests/Api/V1/Auth/LoginRequest.php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'whatsapp_number' => 'required|string|exists:users,whatsapp_number',
            'password' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_number.required' => 'whatsapp number required.',
            'whatapp_number.exists' => 'whatsapp number not found.',
            'password.required' => 'password required.',
        ];
    }
}
