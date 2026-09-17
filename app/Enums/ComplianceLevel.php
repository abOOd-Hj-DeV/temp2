<?php

// app/Enums/ComplianceLevel.php

namespace App\Enums;

enum ComplianceLevel: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'High',
            self::MEDIUM => 'Medium',
            self::LOW => 'Low',
        };
    }

    public function scoreRange(): array
    {
        return match ($this) {
            self::HIGH => [80, 100],
            self::MEDIUM => [50, 79],
            self::LOW => [0, 49],
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
