<?php

namespace App\Services\Messaging;

use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;

class UltraMsgWhatsAppSender implements WhatsAppSenderInterface
{
    public function __construct(
        private ClientInterface $http,
        private string $instanceId,
        private string $token,
        private string $baseUrl = 'https://api.ultramsg.com',
    ) {}

    public function send(string $phoneNumber, string $message): bool
    {
        $response = $this->http->request(
            'POST',
            rtrim($this->baseUrl, '/').'/'.$this->instanceId.'/messages/chat',
            [
                'form_params' => [
                    'token' => $this->token,
                    'to' => $phoneNumber,
                    'body' => $message,
                ],
                'timeout' => 10,
            ]
        );

        $body = json_decode((string) $response->getBody(), true);

        $sent = ($body['sent'] ?? null) === 'true'
            || ($body['sent'] ?? null) === true;

        if (! $sent) {
            Log::error('UltraMsg rejected message', ['status' => $response->getStatusCode()]);
        }

        return $sent;
    }
}
