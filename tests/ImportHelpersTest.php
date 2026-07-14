<?php

namespace DcyphrDigital\Helpers\Tests;

use Carbon\Carbon;
use DcyphrDigital\Helpers\Imports\ImportHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ImportHelpersTest extends TestCase
{
    private object $helpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helpers = new class
        {
            use ImportHelpers;
        };
    }

    private function createDate(mixed $dateString): mixed
    {
        $method = new ReflectionMethod($this->helpers, 'createDate');

        return $method->invoke($this->helpers, $dateString);
    }

    // -------------------------------------------------------------------------
    // clean
    // -------------------------------------------------------------------------

    #[DataProvider('cleanProvider')]
    public function test_clean(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->helpers->clean($input));
    }

    public static function cleanProvider(): array
    {
        return [
            'plain value' => ['hello', 'hello'],
            'leading trailing spaces' => ['  hello  ', 'hello'],
            'double quotes' => ['"hello"', 'hello'],
            'single quotes' => ["'hello'", 'hello'],
            'mixed quotes and spaces' => [' "hello@example.com" ', 'hello@example.com'],
            'empty string' => ['', ''],
            'only spaces' => ['   ', ''],
            'only quotes' => ['""', ''],
            'keeps inner spaces' => ['hello world', 'hello world'],
        ];
    }

    // -------------------------------------------------------------------------
    // normalizeEmail
    // -------------------------------------------------------------------------

    #[DataProvider('normalizeEmailProvider')]
    public function test_normalize_email(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->helpers->normalizeEmail($input));
    }

    public static function normalizeEmailProvider(): array
    {
        return [
            'already lowercase' => ['hello@example.com', 'hello@example.com'],
            'uppercases email' => ['Hello@Example.COM', 'hello@example.com'],
            'quoted with spaces' => [' "Hello@Example.com" ', 'hello@example.com'],
            'single quoted' => ["'User@Domain.IO'", 'user@domain.io'],
            'empty' => ['', ''],
        ];
    }

    // -------------------------------------------------------------------------
    // workoutGender
    // -------------------------------------------------------------------------

    #[DataProvider('workoutGenderProvider')]
    public function test_workout_gender(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->helpers->workoutGender($input));
    }

    public static function workoutGenderProvider(): array
    {
        return [
            'female short lower' => ['f', 'Female'],
            'female short upper' => ['F', 'Female'],
            'female full lower' => ['female', 'Female'],
            'female full mixed' => ['Female', 'Female'],
            'female full upper' => ['FEMALE', 'Female'],
            'male short lower' => ['m', 'Male'],
            'male short upper' => ['M', 'Male'],
            'male full lower' => ['male', 'Male'],
            'male full mixed' => ['Male', 'Male'],
            'male full upper' => ['MALE', 'Male'],
            'unknown word' => ['other', 'Not Provided'],
            'empty string' => ['', 'Not Provided'],
            'woman not matched' => ['woman', 'Not Provided'],
            'man not matched' => ['man', 'Not Provided'],
            'number' => ['1', 'Not Provided'],
        ];
    }

    // -------------------------------------------------------------------------
    // workoutState — by name
    // -------------------------------------------------------------------------

    #[DataProvider('workoutStateByNameProvider')]
    public function test_workout_state_by_name(mixed $state, string $expected): void
    {
        $this->assertSame($expected, $this->helpers->workoutState($state));
    }

    public static function workoutStateByNameProvider(): array
    {
        return [
            'nz abbr' => ['nz', 'NZ'],
            'nz full' => ['new zealand', 'NZ'],
            'nz fuzzy' => ['new zealnd', 'NZ'],
            'act abbr' => ['act', 'ACT'],
            'act full' => ['australian capital territory', 'ACT'],
            'nsw abbr' => ['nsw', 'NSW'],
            'nsw full' => ['new south wales', 'NSW'],
            'nsw fuzzy' => ['new south wale', 'NSW'],
            'nt abbr' => ['nt', 'NT'],
            'nt full' => ['northern territory', 'NT'],
            'qld abbr' => ['qld', 'QLD'],
            'qld full' => ['queensland', 'QLD'],
            'qld case' => ['Queensland', 'QLD'],
            'sa abbr' => ['sa', 'SA'],
            'sa full' => ['south australia', 'SA'],
            'tas abbr' => ['tas', 'TAS'],
            'tas full' => ['tasmania', 'TAS'],
            'vic abbr' => ['vic', 'VIC'],
            'vic full' => ['victoria', 'VIC'],
            'wa abbr' => ['wa', 'WA'],
            'wa full' => ['western australia', 'WA'],
            'unknown name' => ['california', 'Other'],
            'null state' => [null, 'Other'],
            'empty state' => ['', 'Other'],
        ];
    }

    // -------------------------------------------------------------------------
    // workoutState — by postcode (boundaries + mid)
    // -------------------------------------------------------------------------

    #[DataProvider('workoutStateByPostcodeProvider')]
    public function test_workout_state_by_postcode(mixed $postcode, string $expected): void
    {
        $this->assertSame($expected, $this->helpers->workoutState(null, $postcode));
    }

    public static function workoutStateByPostcodeProvider(): array
    {
        return [
            // ACT
            'act 200' => [200, 'ACT'],
            'act 299' => [299, 'ACT'],
            'act 2600' => [2600, 'ACT'],
            'act 2618' => [2618, 'ACT'],
            'act 2900' => [2900, 'ACT'],
            'act 2920' => [2920, 'ACT'],

            // NSW
            'nsw 1000' => [1000, 'NSW'],
            'nsw 1999' => [1999, 'NSW'],
            'nsw 2000' => [2000, 'NSW'],
            'nsw 2599' => [2599, 'NSW'],
            'nsw 2619' => [2619, 'NSW'],
            'nsw 2899' => [2899, 'NSW'],
            'nsw 2921' => [2921, 'NSW'],
            'nsw 2999' => [2999, 'NSW'],

            // NT
            'nt 800' => [800, 'NT'],
            'nt 899' => [899, 'NT'],
            'nt 900' => [900, 'NT'],
            'nt 999' => [999, 'NT'],

            // QLD
            'qld 4000' => [4000, 'QLD'],
            'qld 4999' => [4999, 'QLD'],
            'qld 9000' => [9000, 'QLD'],
            'qld 9999' => [9999, 'QLD'],

            // SA
            'sa 5000' => [5000, 'SA'],
            'sa 5799' => [5799, 'SA'],
            'sa 5800' => [5800, 'SA'],
            'sa 5999' => [5999, 'SA'],

            // TAS
            'tas 7000' => [7000, 'TAS'],
            'tas 7799' => [7799, 'TAS'],
            'tas 7800' => [7800, 'TAS'],
            'tas 7999' => [7999, 'TAS'],

            // VIC
            'vic 3000' => [3000, 'VIC'],
            'vic 3999' => [3999, 'VIC'],
            'vic 8000' => [8000, 'VIC'],
            'vic 8999' => [8999, 'VIC'],

            // WA
            'wa 6000' => [6000, 'WA'],
            'wa 6797' => [6797, 'WA'],
            'wa 6800' => [6800, 'WA'],
            'wa 6999' => [6999, 'WA'],

            // outside ranges
            'other 0' => [0, 'Other'],
            'other 123' => [123, 'Other'],
            'other null' => [null, 'Other'],
        ];
    }

    public function test_workout_state_name_beats_later_postcode_ranges(): void
    {
        // NSW name is evaluated before VIC postcode bands, so NSW wins.
        $this->assertSame('NSW', $this->helpers->workoutState('nsw', '3000'));
        // QLD name is evaluated before WA postcode bands, so QLD wins.
        $this->assertSame('QLD', $this->helpers->workoutState('qld', '6000'));
    }

    public function test_workout_state_earlier_postcode_beats_later_state_name(): void
    {
        // NSW postcode band is checked before the VIC name branch.
        $this->assertSame('NSW', $this->helpers->workoutState('victoria', '2000'));
    }

    // -------------------------------------------------------------------------
    // checkPostcode
    // -------------------------------------------------------------------------

    #[DataProvider('checkPostcodeProvider')]
    public function test_check_postcode(mixed $postcode, mixed $state, bool $expected): void
    {
        $this->assertSame($expected, $this->helpers->checkPostcode($postcode, $state));
    }

    public static function checkPostcodeProvider(): array
    {
        return [
            'empty string' => ['', null, false],
            'null postcode' => [null, null, false],
            'zero treated empty' => [0, null, false],

            // NZ length rules
            'nz 3 digit valid' => ['101', 'nz', true],
            'nz 4 digit valid' => ['6011', 'new zealand', true],
            'nz 2 digit invalid' => ['12', 'nz', false],
            'nz 5 digit invalid' => ['12345', 'nz', false],

            // AU valid ranges
            'act valid' => ['2600', null, true],
            'nsw valid' => ['2000', null, true],
            'nt valid' => ['800', null, true],
            'qld valid' => ['4000', null, true],
            'sa valid' => ['5000', null, true],
            'tas valid' => ['7000', null, true],
            'vic valid' => ['3000', null, true],
            'wa valid' => ['6000', null, true],
            'act short 200' => ['200', null, true],
            'qld 9000' => ['9000', null, true],

            // invalid AU
            'too short invalid' => ['12', null, false],
            'outside ranges' => ['100', null, false],
            'string junk' => ['abc', null, false],
        ];
    }

    // -------------------------------------------------------------------------
    // cleanPhone
    // -------------------------------------------------------------------------

    #[DataProvider('cleanPhoneProvider')]
    public function test_clean_phone(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->helpers->cleanPhone($input));
    }

    public static function cleanPhoneProvider(): array
    {
        return [
            'mobile without leading 0' => ['412345678', '0412345678'],
            'mobile with leading 0' => ['0412345678', '0412345678'],
            'mobile leading zeros trimmed' => ['00412345678', '0412345678'],
            'sydney landline' => ['212345678', '0212345678'],
            'melbourne landline' => ['398765432', '0398765432'],
            'brisbane landline' => ['712345678', '0712345678'],
            'adelaide landline' => ['812345678', '0812345678'],
            'already formatted 02' => ['0212345678', '0212345678'],
            'too short' => ['123', null],
            'too long' => ['04123456789', null],
            'invalid prefix 05' => ['0512345678', null],
            'invalid prefix 06' => ['0612345678', null],
            'invalid prefix 01' => ['0112345678', null],
            'nine digits wrong' => ['41234567', null],
        ];
    }

    // -------------------------------------------------------------------------
    // createDate
    // -------------------------------------------------------------------------

    public function test_create_date_returns_null_for_empty_values(): void
    {
        $this->assertNull($this->createDate(''));
        $this->assertNull($this->createDate(null));
    }

    public function test_create_date_parses_dmy_lengths(): void
    {
        // length 8: d/m/Y without leading zeros e.g. 1/1/2024
        $date8 = $this->createDate('1/1/2024');
        $this->assertInstanceOf(Carbon::class, $date8);
        $this->assertSame('2024-01-01 00:00:00', $date8->format('Y-m-d H:i:s'));

        // length 9: e.g. 1/12/2024 or 12/1/2024
        $date9 = $this->createDate('1/12/2024');
        $this->assertInstanceOf(Carbon::class, $date9);
        $this->assertSame('2024-12-01 00:00:00', $date9->format('Y-m-d H:i:s'));

        // length 10: e.g. 15/03/2024
        $date10 = $this->createDate('15/03/2024');
        $this->assertInstanceOf(Carbon::class, $date10);
        $this->assertTrue($date10->isStartOfDay());
        $this->assertSame('2024-03-15', $date10->toDateString());
    }

    public function test_create_date_parses_dmy_with_time(): void
    {
        // length 14–16: d/m/Y G:i
        $date14 = $this->createDate('15/3/2024 9:30');
        $this->assertInstanceOf(Carbon::class, $date14);
        $this->assertSame('2024-03-15 09:30:00', $date14->format('Y-m-d H:i:s'));

        $date15 = $this->createDate('15/03/2024 9:30');
        $this->assertInstanceOf(Carbon::class, $date15);
        $this->assertSame('2024-03-15 09:30:00', $date15->format('Y-m-d H:i:s'));

        $date16 = $this->createDate('15/03/2024 14:05');
        $this->assertInstanceOf(Carbon::class, $date16);
        $this->assertSame('2024-03-15 14:05:00', $date16->format('Y-m-d H:i:s'));
    }

    public function test_create_date_parses_12h_with_seconds(): void
    {
        $date = $this->createDate('15/03/2024 02:30:45 PM');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame('2024-03-15 14:30:45', $date->format('Y-m-d H:i:s'));
    }

    public function test_create_date_parses_est_and_converts_to_sydney(): void
    {
        $date = $this->createDate('03/15/24 2:30pm EST');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame('Australia/Sydney', $date->timezoneName);
    }

    public function test_create_date_parses_edt_and_converts_to_sydney(): void
    {
        $date = $this->createDate('06/15/24 2:30pm EDT');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame('Australia/Sydney', $date->timezoneName);
    }
}
