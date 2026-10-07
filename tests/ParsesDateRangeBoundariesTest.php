<?php

namespace DcyphrDigital\Helpers\Tests;

use Carbon\Carbon;
use DcyphrDigital\Helpers\Support\ParsesDateRangeBoundaries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParsesDateRangeBoundariesTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new class {
            use ParsesDateRangeBoundaries;

            public function boundary(mixed $value, string $boundary): string
            {
                return $this->dateRangeBoundary($value, $boundary)->format('Y-m-d H:i:s');
            }
        };
    }

    #[DataProvider('boundaryProvider')]
    public function test_a_date_covers_its_whole_day_and_a_datetime_keeps_its_time(mixed $value, string $boundary, string $expected): void
    {
        $this->assertSame($expected, $this->helper->boundary($value, $boundary));
    }

    public static function boundaryProvider(): array
    {
        return [
            'date, from' => ['2026-10-07', 'from', '2026-10-07 00:00:00'],
            'date, to' => ['2026-10-07', 'to', '2026-10-07 23:59:59'],
            'date with spaces around' => ['  2026-10-07 ', 'to', '2026-10-07 23:59:59'],
            'datetime, from' => ['2026-10-07 10:00:00', 'from', '2026-10-07 10:00:00'],
            'datetime, to' => ['2026-10-07 14:30:00', 'to', '2026-10-07 14:30:00'],
            'datetime without seconds' => ['2026-10-07 14:30', 'to', '2026-10-07 14:30:00'],
            'Carbon keeps its time' => [Carbon::parse('2026-10-07 14:30:00'), 'to', '2026-10-07 14:30:00'],
            'Carbon at midnight is not stretched to the end of the day' => [Carbon::parse('2026-10-07 00:00:00'), 'to', '2026-10-07 00:00:00'],
        ];
    }
}
