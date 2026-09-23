<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Bound in production when UltraMsg credentials are missing: every send
 * fails closed so callers surface a delivery error instead of pretending
 * the OTP or alert went out.
 */
class DisabledWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::critical('WhatsApp delivery attempted without UltraMsg configuration', [
            'to_hash' => hash('sha256', $phoneNumber),
        ]);

        return false;
    }
}
