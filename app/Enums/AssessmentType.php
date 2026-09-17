<?php

// app/Enums/AssessmentType.php

namespace App\Enums;

enum AssessmentType: string
{
    case PHQ9 = 'phq9';
    case GAD7 = 'gad7';

    public function label(): string
    {
        return match ($this) {
            self::PHQ9 => 'PHQ-9',
            self::GAD7 => 'GAD-7',
        };
    }

    public function maxScore(): int
    {
        return match ($this) {
            self::PHQ9 => 27,
            self::GAD7 => 21,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
