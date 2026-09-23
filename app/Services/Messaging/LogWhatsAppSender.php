<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Fallback sender used when UltraMsg is not configured (local dev / CI).
 * Nothing is delivered and the message body is never written to the log —
 * it may contain an OTP.
 */
class LogWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::info('WhatsApp message suppressed (UltraMsg not configured)', [
            'to_hash' => hash('sha256', $phoneNumber),
            'length' => strlen($message),
        ]);

        return true;
    }
}
