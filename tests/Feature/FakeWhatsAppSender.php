<?php

namespace Tests\Feature;

use App\Services\Messaging\WhatsAppSenderInterface;

/**
 * Captures outbound WhatsApp messages instead of sending them,
 * so tests can read the OTP straight from the message body.
 */
class FakeWhatsAppSender implements WhatsAppSenderInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $messages = [];

    /** When true, every send is reported as failed without being recorded. */
    public bool $fail = false;

    public function send(string $phoneNumber, string $message): bool
    {
        if ($this->fail) {
            return false;
        }

        $this->messages[] = ['to' => $phoneNumber, 'message' => $message];

        return true;
    }
}
