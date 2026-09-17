<?php

namespace App\Http\Requests\Api\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class SubmitProofRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
