<?php

namespace App\Services\Messaging;

/**
 * Sends a WhatsApp text message to a single recipient.
 * Implementations: UltraMsgWhatsAppSender (production), LogWhatsAppSender (local/testing).
 */
interface WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $message): bool;
}
