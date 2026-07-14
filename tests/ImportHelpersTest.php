<?php

namespace Dcyphr\Helpers\Tests;

use Dcyphr\Helpers\Imports\ImportHelpers;
use Dcyphr\Helpers\Support\Deduplicator;
use PHPUnit\Framework\TestCase;

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

    public function test_clean_trims_quotes_and_whitespace(): void
    {
        $this->assertSame('hello@example.com', $this->helpers->clean(' "hello@example.com" '));
    }

    public function test_normalize_email_lowercases_cleaned_value(): void
    {
        $this->assertSame('hello@example.com', $this->helpers->normalizeEmail(' "Hello@Example.com" '));
    }

    public function test_workout_gender(): void
    {
        $this->assertSame('Female', $this->helpers->workoutGender('f'));
        $this->assertSame('Male', $this->helpers->workoutGender('Male'));
        $this->assertSame('Not Provided', $this->helpers->workoutGender('x'));
    }

    public function test_workout_state_from_postcode(): void
    {
        $this->assertSame('NSW', $this->helpers->workoutState(null, '2000'));
        $this->assertSame('VIC', $this->helpers->workoutState(null, '3000'));
        $this->assertSame('QLD', $this->helpers->workoutState('queensland'));
    }

    public function test_clean_phone(): void
    {
        $this->assertSame('0412345678', $this->helpers->cleanPhone('412345678'));
        $this->assertNull($this->helpers->cleanPhone('123'));
    }

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
}
