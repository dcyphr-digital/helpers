<?php

namespace DcyphrDigital\Helpers\Support;

/**
 * Loyalty points from money: worked out in whole cents, as integers, so they are exact. With floats, a value such as
 * 3 × 1.90 × 5 = 28.5 is held as 28.4999…, and rounding it then depends on the PHP version (8.3 pre-rounds it, 8.4
 * does not).
 */
final class Points
{
    /**
     * The points for an amount in cents, at $perUnit points per unit of money: rounded UP to a whole point
     * (59.97 → 60, 1.01 → 2), and never below 0, e.g. when discounts or returned items make the amount negative.
     */
    public static function fromCents(int $cents, int $perUnit = 1): int
    {
        $pointsInCents = $cents * $perUnit;

        return $pointsInCents > 0 ? intdiv($pointsInCents + 99, 100) : 0;
    }

    /**
     * An amount with at most two decimals (e.g. a decimal(10,2) column, or its string), as whole cents: × 100 is then
     * a whole number give or take a float's tiny error, far from a half, so rounding it is exact on any PHP version.
     */
    public static function toCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
