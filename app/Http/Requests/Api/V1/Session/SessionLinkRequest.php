<?php

namespace App\Http\Requests\Api\V1\Session;

use Illuminate\Foundation\Http\FormRequest;

class SessionLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'link' => 'required|url|max:500',
        ];
    }
}
