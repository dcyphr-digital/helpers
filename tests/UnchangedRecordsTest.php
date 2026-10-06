<?php

namespace DcyphrDigital\Helpers\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DcyphrDigital\Helpers\Services\Database\UnchangedRecords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class UnchangedRecordsTest extends TestCase
{
    private UnchangedRecords $unchangedRecords;

    private const array MATCH_KEYS = ['brand_id', 'crm_id'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->unchangedRecords = new UnchangedRecords;
    }

    private static function update(int $crmId, array $data): array
    {
        return [
            'data' => ['brand_id' => 1, 'crm_id' => $crmId, ...$data],
            'existing' => ['brand_id' => 1, 'crm_id' => $crmId],
        ];
    }

    private static function stored(int $crmId, array $values): array
    {
        return ['brand_id' => 1, 'crm_id' => $crmId, ...$values];
    }

    // sameStoredValue

    #[DataProvider('sameValueProvider')]
    public function test_same_stored_value(mixed $stored, mixed $new): void
    {
        $this->assertTrue($this->unchangedRecords->sameStoredValue($stored, $new));
    }

    public static function sameValueProvider(): array
    {
        return [
            'both null' => [null, null],
            'same text' => ['john@example.com', 'john@example.com'],
            'both empty text' => ['', ''],
            'text with the same spaces' => [' john ', ' john '],
            'integer and same integer' => [150, 150],
            'integer column and numeric text' => [150, '150'],
            'integer column and float of the same value' => [150, 150.0],
            'zero integer and zero text' => [0, '0'],
            'negative integer and negative text' => [-5, '-5'],
            'float column and same float' => [10.5, 10.5],
            'float column and text with a trailing zero' => [10.5, '10.50'],
            'float column and integer of the same value' => [10.0, 10],
            'text column and integer written the same way' => ['123', 123],
            'text column and float written the same way' => ['10.5', 10.5],
            'tinyint and true' => [1, true],
            'tinyint and false' => [0, false],
            'text one and true' => ['1', true],
            'text zero and false' => ['0', false],
            'date column and the date at midnight' => ['1990-01-01', '1990-01-01 00:00:00'],
            'datetime and the date at midnight' => ['1990-01-01 00:00:00', '1990-01-01'],
            'same date' => ['1990-01-01', '1990-01-01'],
            'same datetime' => ['2026-10-06 09:30:00', '2026-10-06 09:30:00'],
            'date column and Carbon at midnight' => ['1990-01-01', Carbon::parse('1990-01-01')],
            'datetime and Carbon' => ['2026-10-06 09:30:00', Carbon::parse('2026-10-06 09:30:00')],
            'datetime and CarbonImmutable' => ['2026-10-06 09:30:00', CarbonImmutable::parse('2026-10-06 09:30:00')],
            'datetime and DateTime' => ['2026-10-06 09:30:00', new DateTime('2026-10-06 09:30:00')],
            'datetime and DateTimeImmutable' => ['2026-10-06 09:30:00', new DateTimeImmutable('2026-10-06 09:30:00')],
            'enum text' => ['Yes', 'Yes'],
            'unicode text' => ['Zoë', 'Zoë'],
        ];
    }

    #[DataProvider('changedValueProvider')]
    public function test_changed_stored_value(mixed $stored, mixed $new): void
    {
        $this->assertFalse($this->unchangedRecords->sameStoredValue($stored, $new));
    }

    public static function changedValueProvider(): array
    {
        return [
            'null and empty text' => [null, ''],
            'empty text and null' => ['', null],
            'null and zero' => [null, 0],
            'zero and null' => [0, null],
            'null and false' => [null, false],
            'text and null' => ['Smith', null],
            'different text' => ['Smith', 'Smyth'],
            // The new letter case is written
            'letter case' => ['john@example.com', 'John@example.com'],
            'leading space' => ['john', ' john'],
            'trailing space' => ['john', 'john '],
            'integer change' => [150, 151],
            'integer column and non-numeric text' => [150, 'abc'],
            'zero integer and empty text' => [0, ''],
            'float change' => [10.5, 10.51],
            // A text column keeps the text as written
            'text column with a leading zero and an integer' => ['0123', 123],
            'text column with a trailing zero and a float' => ['10.50', 10.5],
            'text column number and a different integer' => ['123', 124],
            'tinyint and the other boolean' => [1, false],
            'different date' => ['1990-01-01', '1990-01-02'],
            'date column and the date at a time' => ['1990-01-01', '1990-01-01 00:00:01'],
            'different time' => ['2026-10-06 09:30:00', '2026-10-06 09:30:01'],
            'date column and Carbon at a time' => ['1990-01-01', Carbon::parse('1990-01-01 08:00:00')],
            'datetime and a different Carbon' => ['2026-10-06 09:30:00', Carbon::parse('2026-10-07 09:30:00')],
            'date-like text that is not a date format' => ['01/01/1990', '1990-01-01'],
            'array value' => ['["a"]', ['a']],
            'object value' => ['{}', new stdClass],
            'stored array' => [['a'], 'a'],
        ];
    }

    // wouldChange

    public function test_would_not_change_when_every_compared_column_is_the_same(): void
    {
        $this->assertFalse($this->unchangedRecords->wouldChange(
            ['email' => 'a@x.com', 'first_name' => 'Anna'],
            ['email' => 'a@x.com', 'first_name' => 'Anna'],
            ['email', 'first_name'],
        ));
    }

    public function test_would_change_when_one_compared_column_differs(): void
    {
        $this->assertTrue($this->unchangedRecords->wouldChange(
            ['email' => 'a@x.com', 'first_name' => 'Anne'],
            ['email' => 'a@x.com', 'first_name' => 'Anna'],
            ['email', 'first_name'],
        ));
    }

    public function test_would_not_change_when_only_a_column_not_compared_differs(): void
    {
        $this->assertFalse($this->unchangedRecords->wouldChange(
            ['email' => 'a@x.com', 'last_name' => 'Different'],
            ['email' => 'a@x.com', 'last_name' => 'Stored'],
            ['email'],
        ));
    }

    public function test_would_not_change_for_a_compared_column_the_item_does_not_carry(): void
    {
        $this->assertFalse($this->unchangedRecords->wouldChange(
            ['email' => 'a@x.com'],
            ['email' => 'a@x.com', 'first_name' => 'Anna'],
            ['email', 'first_name'],
        ));
    }

    public function test_would_change_when_a_compared_column_was_not_loaded(): void
    {
        $this->assertTrue($this->unchangedRecords->wouldChange(
            ['email' => 'a@x.com', 'first_name' => 'Anna'],
            ['email' => 'a@x.com'],
            ['email', 'first_name'],
        ));
    }

    public function test_would_not_change_with_no_columns_to_compare(): void
    {
        $this->assertFalse($this->unchangedRecords->wouldChange(['email' => 'new@x.com'], ['email' => 'old@x.com'], []));
    }

    public function test_compares_the_default_value_instead_of_the_item_value(): void
    {
        $this->assertFalse($this->unchangedRecords->wouldChange(
            ['first_name' => 'Ignored'],
            ['first_name' => 'Stored'],
            ['first_name'],
            ['first_name' => 'Stored'],
        ));

        $this->assertTrue($this->unchangedRecords->wouldChange(
            ['first_name' => 'Stored'],
            ['first_name' => 'Stored'],
            ['first_name'],
            ['first_name' => 'Default'],
        ));
    }

    public function test_compares_a_default_value_for_a_column_the_item_does_not_carry(): void
    {
        $this->assertTrue($this->unchangedRecords->wouldChange([], ['first_name' => 'Stored'], ['first_name'], ['first_name' => 'Default']));
        $this->assertFalse($this->unchangedRecords->wouldChange([], ['first_name' => 'Stored'], ['first_name'], ['first_name' => 'Stored']));
    }

    public function test_a_null_default_value_is_compared_too(): void
    {
        $this->assertTrue($this->unchangedRecords->wouldChange(['first_name' => 'Stored'], ['first_name' => 'Stored'], ['first_name'], ['first_name' => null]));
        $this->assertFalse($this->unchangedRecords->wouldChange(['first_name' => 'Ignored'], ['first_name' => null], ['first_name'], ['first_name' => null]));
    }

    public function test_an_item_value_of_null_is_compared(): void
    {
        $this->assertTrue($this->unchangedRecords->wouldChange(['first_name' => null], ['first_name' => 'Anna'], ['first_name']));
        $this->assertFalse($this->unchangedRecords->wouldChange(['first_name' => null], ['first_name' => null], ['first_name']));
    }

    // columnsToCompare

    public function test_columns_without_rules_are_the_reliable_keys_less_the_match_keys(): void
    {
        $this->assertSame(
            ['email', 'first_name'],
            $this->unchangedRecords->columnsToCompare([self::update(1, ['last_name' => 'X'])], self::MATCH_KEYS, ['brand_id', 'email', 'first_name', 'crm_id'], false),
        );
    }

    public function test_columns_without_rules_and_without_reliable_keys_are_empty(): void
    {
        $this->assertSame([], $this->unchangedRecords->columnsToCompare([self::update(1, ['email' => 'a@x.com'])], self::MATCH_KEYS, [], false));
    }

    public function test_columns_without_rules_when_every_reliable_key_is_a_match_key_are_empty(): void
    {
        $this->assertSame([], $this->unchangedRecords->columnsToCompare([self::update(1, [])], self::MATCH_KEYS, ['brand_id', 'crm_id'], false));
    }

    public function test_columns_with_rules_are_every_item_column_less_the_match_keys(): void
    {
        $columns = $this->unchangedRecords->columnsToCompare(
            [self::update(1, ['email' => 'a@x.com', 'first_name' => 'Anna']), self::update(2, ['email' => 'b@x.com', 'last_name' => 'Smith'])],
            self::MATCH_KEYS,
            ['email'],
            true,
        );

        $this->assertSame(['email', 'first_name', 'last_name'], $columns);
    }

    public function test_columns_with_rules_and_no_updates_are_empty(): void
    {
        $this->assertSame([], $this->unchangedRecords->columnsToCompare([], self::MATCH_KEYS, ['email'], true));
    }

    // partition

    public function test_partition_splits_changed_and_unchanged_updates(): void
    {
        $anna = self::update(1, ['email' => 'anna@x.com']);
        $ben = self::update(2, ['email' => 'ben@x.com']);
        $cara = self::update(3, ['email' => 'cara@new.com']);

        $partition = $this->unchangedRecords->partition(
            [$anna, $ben, $cara],
            [self::stored(1, ['email' => 'anna@x.com']), self::stored(2, ['email' => 'ben@x.com']), self::stored(3, ['email' => 'cara@old.com'])],
            self::MATCH_KEYS,
            ['email'],
        );

        $this->assertSame([$cara], $partition['changed']);
        $this->assertSame([$anna['data'], $ben['data']], $partition['unchanged']);
    }

    public function test_partition_keeps_the_updates_in_their_order(): void
    {
        $updates = [self::update(3, ['email' => 'c@new.com']), self::update(1, ['email' => 'a@new.com']), self::update(2, ['email' => 'b@new.com'])];

        $partition = $this->unchangedRecords->partition(
            $updates,
            [self::stored(1, ['email' => 'a@old.com']), self::stored(2, ['email' => 'b@old.com']), self::stored(3, ['email' => 'c@old.com'])],
            self::MATCH_KEYS,
            ['email'],
        );

        $this->assertSame($updates, $partition['changed']);
    }

    public function test_partition_counts_an_update_whose_stored_record_was_not_found_as_a_change(): void
    {
        $update = self::update(1, ['email' => 'a@x.com']);

        $partition = $this->unchangedRecords->partition([$update], [self::stored(2, ['email' => 'a@x.com'])], self::MATCH_KEYS, ['email']);

        $this->assertSame([$update], $partition['changed']);
        $this->assertSame([], $partition['unchanged']);
    }

    public function test_partition_counts_every_update_as_a_change_with_no_columns_to_compare(): void
    {
        $update = self::update(1, ['email' => 'a@x.com']);

        $partition = $this->unchangedRecords->partition([$update], [self::stored(1, ['email' => 'a@x.com'])], self::MATCH_KEYS, []);

        $this->assertSame([$update], $partition['changed']);
    }

    public function test_partition_with_no_updates_is_empty(): void
    {
        $this->assertSame(['changed' => [], 'unchanged' => []], $this->unchangedRecords->partition([], [], self::MATCH_KEYS, ['email']));
    }

    public function test_partition_matches_stored_records_whose_match_keys_differ_in_letter_case(): void
    {
        // The lookup keys are lowercased, as MySQL matches the records ignoring case
        $update = [
            'data' => ['brand_id' => 1, 'email' => 'Anna@X.com', 'first_name' => 'Anna'],
            'existing' => ['brand_id' => 1, 'email' => 'Anna@X.com'],
        ];

        $partition = $this->unchangedRecords->partition(
            [$update],
            [['brand_id' => 1, 'email' => 'anna@x.com', 'first_name' => 'Anna']],
            ['brand_id', 'email'],
            ['first_name'],
        );

        $this->assertSame([$update['data']], $partition['unchanged']);
    }

    public function test_partition_matches_stored_records_by_all_match_keys(): void
    {
        // Same crm_id, another brand: not this update's record
        $update = self::update(1, ['email' => 'a@x.com']);

        $partition = $this->unchangedRecords->partition([$update], [['brand_id' => 2, 'crm_id' => 1, 'email' => 'a@x.com']], self::MATCH_KEYS, ['email']);

        $this->assertSame([$update], $partition['changed']);
    }

    public function test_partition_matches_stored_records_with_a_null_match_key(): void
    {
        $update = [
            'data' => ['brand_id' => 1, 'website_id' => null, 'email' => 'a@x.com'],
            'existing' => ['brand_id' => 1, 'website_id' => null],
        ];

        $partition = $this->unchangedRecords->partition(
            [$update],
            [['brand_id' => 1, 'website_id' => null, 'email' => 'a@x.com']],
            ['brand_id', 'website_id'],
            ['email'],
        );

        $this->assertSame([$update['data']], $partition['unchanged']);
    }

    public function test_partition_uses_the_default_values(): void
    {
        $update = self::update(1, ['first_name' => 'Ignored']);

        $partition = $this->unchangedRecords->partition([$update], [self::stored(1, ['first_name' => 'Stored'])], self::MATCH_KEYS, ['first_name'], ['first_name' => 'Stored']);

        $this->assertSame([$update['data']], $partition['unchanged']);
    }

    public function test_partition_compares_raw_stored_values(): void
    {
        // A date column returns the date only; the item carries it with a time, a number as text
        $update = self::update(1, ['date_of_birth' => '1990-01-01 00:00:00', 'points' => '150']);

        $partition = $this->unchangedRecords->partition(
            [$update],
            [self::stored(1, ['date_of_birth' => '1990-01-01', 'points' => 150])],
            self::MATCH_KEYS,
            ['date_of_birth', 'points'],
        );

        $this->assertSame([$update['data']], $partition['unchanged']);
    }

    public function test_partition_uses_the_last_stored_row_when_two_share_match_keys(): void
    {
        // Only possible without a unique index on the match keys; the later row decides
        $update = self::update(1, ['email' => 'second@x.com']);

        $partition = $this->unchangedRecords->partition(
            [$update],
            [self::stored(1, ['email' => 'first@x.com']), self::stored(1, ['email' => 'second@x.com'])],
            self::MATCH_KEYS,
            ['email'],
        );

        $this->assertSame([$update['data']], $partition['unchanged']);
    }
}
