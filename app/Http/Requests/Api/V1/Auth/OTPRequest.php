<?php
// app/Http/Requests/Api/V1/Auth/OTPRequest.php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator; // ⬅️ تأكد من أن هذا هو الكلاس المُستورد
use Illuminate\Http\Exceptions\HttpResponseException; // ⬅️ تأكد من استيراد هذا الكلاس
class OTPRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // يجب السماح للجميع بطلب التحقق
    }

    public function rules(): array
    {
        return [
            // يجب أن يكون رقم الواتساب موجوداً في الـ Body
            'whatsapp_number' => 'required|string|exists:users,whatsapp_number',
            'otp' => 'required|string|min:6|max:6', // يجب أن يكون الرمز 6 أرقام
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_number.required' => 'رقم الواتساب مطلوب.',
            'whatsapp_number.exists' => 'رقم الواتساب غير مسجل.',
            'otp.required' => 'رمز التحقق مطلوب.',
            'otp.min' => 'رمز التحقق يجب أن يكون 6 أرقام.',
            'otp.max' => 'رمز التحقق يجب أن يكون 6 أرقام.',
        ];

    }
    protected function failedValidation(Validator $validator)
    {
        // هذا يضمن أن فشل التحقق يعيد 422 JSON بدلاً من Redirect
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
