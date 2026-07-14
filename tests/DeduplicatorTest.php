<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\Deduplicator;
use PHPUnit\Framework\TestCase;

class DeduplicatorTest extends TestCase
{
    public function test_deduplicate_latest_by_brand_and_email(): void
    {
        $items = [
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-01-01', 'name' => 'old'],
            ['brand_id' => 1, 'email' => 'a@test.com', 'source_created_date' => '2024-06-01', 'name' => 'new'],
            ['brand_id' => 2, 'email' => 'a@test.com', 'source_created_date' => '2024-03-01', 'name' => 'other'],
        ];

        $result = Deduplicator::latestByBrandAndEmail($items);

        $this->assertCount(2, $result);
        $this->assertSame('new', collect($result)->firstWhere('brand_id', 1)['name']);
        $this->assertSame('other', collect($result)->firstWhere('brand_id', 2)['name']);
    }

    public function test_deduplicate_latest_by_keys(): void
    {
        $items = [
            ['key' => 'a', 'source_created_date' => '2024-01-01', 'value' => 1],
            ['key' => 'a', 'source_created_date' => '2024-02-01', 'value' => 2],
            ['key' => 'b', 'source_created_date' => '2024-01-15', 'value' => 3],
        ];

        $result = Deduplicator::latestByKeys($items, ['key']);

        $this->assertCount(2, $result);
        $this->assertSame(2, collect($result)->firstWhere('key', 'a')['value']);
        $this->assertSame(3, collect($result)->firstWhere('key', 'b')['value']);
    }
}
