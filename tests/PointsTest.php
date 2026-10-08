<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\Points;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PointsTest extends TestCase
{
    #[DataProvider('fromCentsProvider')]
    public function test_rounds_up_to_a_whole_point_and_never_below_zero(int $cents, int $perUnit, int $expected): void
    {
        $this->assertSame($expected, Points::fromCents($cents, $perUnit));
    }

    public static function fromCentsProvider(): array
    {
        return [
            'whole amount' => [12500, 1, 125],
            'cents round up' => [5997, 1, 60],
            'a cent over rounds up' => [101, 1, 2],
            'per unit multiplies first' => [5997, 10, 600],
            'a half rounds up' => [2850, 1, 29],
            'zero' => [0, 10, 0],
            'negative is 0' => [-1050, 10, 0],
            'negative per unit is 0' => [1000, -1, 0],
        ];
    }

    #[DataProvider('toCentsProvider')]
    public function test_turns_an_amount_into_whole_cents(mixed $amount, int $expected): void
    {
        $this->assertSame($expected, Points::toCents($amount));
    }

    public static function toCentsProvider(): array
    {
        return [
            'decimal string' => ['59.97', 5997],
            'float' => [19.99, 1999],
            'integer' => [125, 12500],
            'negative' => ['-10.50', -1050],
            'null' => [null, 0],
            'float held below its value' => [1.9 * 3, 570],
        ];
    }

    public function test_is_exact_where_floats_are_not(): void
    {
        // 3 × 1.90 × 5 = 28.5 exactly, which floats hold as 28.4999… and would round down
        $this->assertSame(29, Points::fromCents(Points::toCents('1.90') * 3, 5));
    }
}
