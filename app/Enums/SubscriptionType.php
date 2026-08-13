<?php
// app/Enums/SubscriptionType.php

namespace App\Enums;

enum SubscriptionType: string
{
    case FOUR_WEEKS = '4_weeks';
    case EIGHT_WEEKS = '8_weeks';

    public function label(): string
    {
        return match($this) {
            self::FOUR_WEEKS => '4 Weeks',
            self::EIGHT_WEEKS => '8 Weeks',
        };
    }

    public function durationInWeeks(): int
    {
        return match($this) {
            self::FOUR_WEEKS => 4,
            self::EIGHT_WEEKS => 8,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
