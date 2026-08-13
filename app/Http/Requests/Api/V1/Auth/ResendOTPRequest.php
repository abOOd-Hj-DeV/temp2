<?php
// app/Http/Requests/Api/V1/Auth/ResendOTPRequest.php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResendOTPRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // نحتاج فقط لرقم الواتساب الموجود في قاعدة البيانات
            'whatsapp_number' => 'required|string|exists:users,whatsapp_number',
        ];
    }
}
