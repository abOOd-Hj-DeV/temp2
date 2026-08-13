<?php
// app/Http/Requests/Api/V1/Auth/RegisterRequest.php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException; // ⬅️ تأكد من استدعاء هذا الكلاس

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:' . implode(',', array_column(UserRole::cases(), 'value')),
            'whatsapp_number' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.unique' => 'البريد الإلكتروني مسجل مسبقاً.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
            'whatsapp_number.required' => 'رقم الواتساب مطلوب.',
            'whatsapp_number.unique' => 'رقم الواتساب مسجل مسبقاً.',
        ];
    }

    /**
     * 🚀 تجاوز دالة فشل التحقق لإرجاع استجابة JSON (422) بدلاً من التوجيه (Redirect).
     */
    protected function failedValidation(Validator $validator)
    {
        // نرمي استثناء HTTP بدلاً من التوجيه (Redirect)
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.', // رسالة عامة للـ API
                'errors' => $validator->errors(), // تفاصيل أخطاء التحقق
            ], 422) // رمز 422 Unprocessable Entity
        );
    }
}
