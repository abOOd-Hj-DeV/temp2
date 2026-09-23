<?php

namespace App\Http\Requests\Api\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => 'required|in:approve,reject',
            'note' => 'required_if:action,reject|nullable|string|max:1000',
        ];
    }
}
