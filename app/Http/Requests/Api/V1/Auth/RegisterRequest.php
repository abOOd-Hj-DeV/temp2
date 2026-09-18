<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('whatsapp_number'))) {
            $this->merge(['whatsapp_number' => PhoneNumber::normalize($this->input('whatsapp_number')) ?? $this->input('whatsapp_number')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'whatsapp_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
        ];
    }
}
