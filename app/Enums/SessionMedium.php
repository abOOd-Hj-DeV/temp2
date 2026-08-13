<?php
// app/Enums/SessionMedium.php

namespace App\Enums;

enum SessionMedium: string
{
    case ZOOM = 'zoom';
    case MEET = 'meet';
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match($this) {
            self::ZOOM => 'Zoom',
            self::MEET => 'Google Meet',
            self::WHATSAPP => 'WhatsApp',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
