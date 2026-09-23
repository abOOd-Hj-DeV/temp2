<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Services\Account\StaffAccountService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ActivateAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('whatsapp_number'))) {
            $this->merge(['whatsapp_number' => PhoneNumber::normalize($this->input('whatsapp_number')) ?? $this->input('whatsapp_number')]);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'whatsapp_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'code' => ['required', 'string', 'size:'.StaffAccountService::CODE_LENGTH, 'alpha_num:ascii'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
