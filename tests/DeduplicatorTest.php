<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\Deduplicator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeduplicatorTest extends TestCase
{
    public function test_latest_by_keys_for_brand_and_email(): void
    {
        $items = [
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-01-01', 'name' => 'old'],
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-06-01', 'name' => 'new'],
            ['brand_id' => 2, 'email' => 'a@test.com', 'source_created_date' => '2024-03-01', 'name' => 'other'],
        ];

        $result = Deduplicator::latestByKeys($items, ['brand_id', 'email'], 'source_created_date');

        $this->assertCount(2, $result);
        $this->assertSame('new', collect($result)->firstWhere('brand_id', 1)['name']);
        $this->assertSame('other', collect($result)->firstWhere('brand_id', 2)['name']);
    }

    public function test_latest_by_keys_with_sort_key_keeps_greatest_value(): void
    {
        $items = [
            ['key' => 'a', 'source_created_date' => '2024-01-01', 'value' => 1],
            ['key' => 'a', 'source_created_date' => '2024-02-01', 'value' => 2],
            ['key' => 'b', 'source_created_date' => '2024-01-15', 'value' => 3],
        ];

        $result = Deduplicator::latestByKeys($items, ['key'], 'source_created_date');

        $this->assertCount(2, $result);
        $this->assertSame(2, collect($result)->firstWhere('key', 'a')['value']);
        $this->assertSame(3, collect($result)->firstWhere('key', 'b')['value']);
    }

    public function test_latest_by_keys_without_sort_key_keeps_last_in_group_order(): void
    {
        $items = [
            ['key' => 'a', 'name' => 'first'],
            ['key' => 'a', 'name' => 'second'],
            ['key' => 'b', 'name' => 'only'],
        ];

        $result = Deduplicator::latestByKeys($items, ['key'], null);

        $this->assertCount(2, $result);
        $this->assertSame('second', collect($result)->firstWhere('key', 'a')['name']);
        $this->assertSame('only', collect($result)->firstWhere('key', 'b')['name']);
    }

    public function test_latest_by_keys_omitted_sort_key_defaults_to_null(): void
    {
        $items = [
            ['key' => 'a', 'name' => 'first'],
            ['key' => 'a', 'name' => 'second'],
        ];

        $result = Deduplicator::latestByKeys($items, ['key']);

        $this->assertCount(1, $result);
        $this->assertSame('second', $result[0]['name']);
    }

    public function test_latest_by_keys_with_multiple_group_keys(): void
    {
        $items = [
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-01-01', 'name' => 'old-a'],
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-06-01', 'name' => 'new-a'],
            ['brand_id' => 1, 'email' => 'b@test.com', 'source_created_date' => '2024-03-01', 'name' => 'b'],
            ['brand_id' => 2, 'email' => 'a@test.com', 'source_created_date' => '2024-02-01', 'name' => 'brand2'],
        ];

        $result = Deduplicator::latestByKeys($items, ['brand_id', 'email'], 'source_created_date');

        $this->assertCount(3, $result);
        $this->assertSame('new-a', collect($result)->first(
            fn (array $row): bool => $row['brand_id'] === 1 && $row['email'] === 'a@test.com'
        )['name']);
        $this->assertSame('b', collect($result)->first(
            fn (array $row): bool => $row['brand_id'] === 1 && $row['email'] === 'b@test.com'
        )['name']);
        $this->assertSame('brand2', collect($result)->firstWhere('brand_id', 2)['name']);
    }

    public function test_latest_by_keys_with_empty_items(): void
    {
        $this->assertSame([], Deduplicator::latestByKeys([], ['key'], 'source_created_date'));
        $this->assertSame([], Deduplicator::latestByKeys([], ['key'], null));
    }

    public function test_latest_by_keys_single_item_group(): void
    {
        $items = [
            ['key' => 'only', 'source_created_date' => '2024-01-01', 'name' => 'solo'],
        ];

        $result = Deduplicator::latestByKeys($items, ['key'], 'source_created_date');

        $this->assertCount(1, $result);
        $this->assertSame('solo', $result[0]['name']);
    }

    #[DataProvider('missingSortKeyValuesProvider')]
    public function test_latest_by_keys_treats_missing_sort_values_as_empty_string(array $items, string $expectedName): void
    {
        $result = Deduplicator::latestByKeys($items, ['key'], 'source_created_date');

        $this->assertCount(1, $result);
        $this->assertSame($expectedName, $result[0]['name']);
    }

    public static function missingSortKeyValuesProvider(): array
    {
        return [
            'missing key sorts as empty, dated row wins' => [
                [
                    ['key' => 'a', 'name' => 'no-date'],
                    ['key' => 'a', 'source_created_date' => '2024-01-01', 'name' => 'dated'],
                ],
                'dated',
            ],
            'null date sorts as empty, dated row wins' => [
                [
                    ['key' => 'a', 'source_created_date' => null, 'name' => 'null-date'],
                    ['key' => 'a', 'source_created_date' => '2024-01-01', 'name' => 'dated'],
                ],
                'dated',
            ],
        ];
    }

    public function test_latest_by_keys_missing_group_key_treated_as_empty(): void
    {
        $items = [
            ['source_created_date' => '2024-01-01', 'name' => 'first'],
            ['source_created_date' => '2024-02-01', 'name' => 'second'],
        ];

        $result = Deduplicator::latestByKeys($items, ['missing'], 'source_created_date');

        $this->assertCount(1, $result);
        $this->assertSame('second', $result[0]['name']);
    }
}
