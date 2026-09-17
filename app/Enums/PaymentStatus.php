<?php

// app/Enums/PaymentStatus.php

namespace App\Enums;

enum PaymentStatus: string
{
    case PAID = 'paid';
    case PENDING = 'pending';
    case FREE = 'free';

    public function label(): string
    {
        return match ($this) {
            self::PAID => 'Paid',
            self::PENDING => 'Pending',
            self::FREE => 'Free',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
