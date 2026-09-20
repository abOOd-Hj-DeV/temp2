<?php

namespace App\Http\Requests\Api\V1\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Package id (preferred) or legacy code such as "4_weeks".
            'package_id' => 'required_without:type|nullable|uuid',
            'type' => 'required_without:package_id|nullable|string|max:64',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
