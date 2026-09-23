<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ResendOTPRequest extends FormRequest
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
            'whatsapp_number' => ['required', 'string'],
        ];
    }
}
