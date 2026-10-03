<?php

namespace App\Http\Requests\Api\V1\Auth;

class LoginChallengeRequest extends OTPRequest
{
    public function rules(): array
    {
        return parent::rules() + ['login_challenge' => ['required', 'string', 'size:64']];
    }
}
