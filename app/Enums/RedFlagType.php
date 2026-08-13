<?php
// app/Enums/RedFlagType.php

namespace App\Enums;

enum RedFlagType: string
{
    case LOW_MOOD = 'low_mood';
    case NON_COMPLIANCE = 'non_compliance';
    case SAFETY = 'safety';

    public function label(): string
    {
        return match($this) {
            self::LOW_MOOD => 'Low Mood',
            self::NON_COMPLIANCE => 'Non Compliance',
            self::SAFETY => 'Safety Concern',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
