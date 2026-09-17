<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Fallback sender used when UltraMsg is not configured (local dev / CI).
 * Messages are written to the log instead of being delivered.
 */
class LogWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::info('WhatsApp message (not sent — UltraMsg not configured)', [
            'message' => $message,
        ]);

        return true;
    }
}
