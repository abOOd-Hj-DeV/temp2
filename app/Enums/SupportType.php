<?php

// app/Enums/SupportType.php

namespace App\Enums;

enum SupportType: string
{
    case TECHNICAL = 'technical';
    case CLINICAL = 'clinical';

    public function label(): string
    {
        return match ($this) {
            self::TECHNICAL => 'Technical',
            self::CLINICAL => 'Clinical',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
