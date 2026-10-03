<?php

namespace App\Http\Requests\Api\V1\Auth;

class LoginChallengeResendRequest extends ResendOTPRequest
{
    public function rules(): array
    {
        return parent::rules() + ['login_challenge' => ['required', 'string', 'size:64']];
    }
}
