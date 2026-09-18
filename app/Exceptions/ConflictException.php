<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when a concurrent request already changed the resource
 * (double booking, double review, duplicate submission). Rendered as 409.
 */
class ConflictException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
