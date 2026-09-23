<?php

namespace App\Support;

/**
 * Canonical E.164-style representation ("+" followed by digits) so the
 * same subscriber can never register twice under "00962…", "962…" and
 * "+962…".
 */
final class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits === '' ? null : '+'.$digits;
    }

    /** Both spellings that may already exist in storage for a number. */
    public static function variants(string $raw): array
    {
        $normalized = self::normalize($raw);

        if ($normalized === null) {
            return [$raw];
        }

        return array_values(array_unique([$normalized, substr($normalized, 1), $raw]));
    }
}
