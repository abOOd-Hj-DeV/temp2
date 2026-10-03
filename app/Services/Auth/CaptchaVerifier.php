<?php

namespace App\Services\Auth;

interface CaptchaVerifier
{
    public function verify(string $token, string $ip, string $action): bool;
}
