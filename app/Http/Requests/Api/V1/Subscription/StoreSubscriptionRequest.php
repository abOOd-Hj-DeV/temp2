<?php

namespace App\Http\Requests\Api\V1\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|in:4_weeks,8_weeks',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
