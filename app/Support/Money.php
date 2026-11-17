<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money is an integer count of nanodollars (1 USD = 1_000_000_000).
 * Pixelforge reports costs with 9 decimals, so provider costs are always exact.
 * No floats anywhere in money math.
 */
final class Money
{
    public const NANO = 1_000_000_000;

    /** Markup applied on top of provider cost: 25%, expressed as an integer percentage. */
    public const MARKUP_PERCENT = 25;

    /** Parse an exact decimal string ("0.046125000", "12.50") into an integer of 10^-$scale units. */
    public static function parseDecimal(string $value, int $scale): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: {$value}");
        }

        $frac = $m[2] ?? '';
        if (strlen($frac) > $scale && rtrim(substr($frac, $scale), '0') !== '') {
            throw new InvalidArgumentException("{$value} has more than {$scale} decimals");
        }

        return (int) ($m[1] . str_pad(substr($frac, 0, $scale), $scale, '0'));
    }

    /** Pixelforge's per-1M-token rate in dollars ("12.50") -> nanodollars per single token (12500). */
    public static function ratePerTokenNano(string $ratePerMillion): int
    {
        // $r per 1M tokens = r * 1e9 / 1e6 nanodollars per token = r * 1000.
        return self::parseDecimal($ratePerMillion, 3);
    }

    /**
     * What the user pays: provider cost + 25%, in whole nanodollars, rounded half up.
     * (Exact when tokens*rate is even; otherwise at most half a nanodollar of rounding.)
     */
    public static function withMarkup(int $providerNano): int
    {
        return intdiv($providerNano * (100 + self::MARKUP_PERCENT) + 50, 100);
    }

    /** Integer nanodollars -> "0.046125000" (always 9 decimals, like Pixelforge). */
    public static function toDecimal(int $nano): string
    {
        $s = str_pad((string) $nano, 10, '0', STR_PAD_LEFT);

        return substr($s, 0, -9) . '.' . substr($s, -9);
    }
}
