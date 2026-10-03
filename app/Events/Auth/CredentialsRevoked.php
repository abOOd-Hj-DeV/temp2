<?php

namespace App\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CredentialsRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $userId) {}
}
