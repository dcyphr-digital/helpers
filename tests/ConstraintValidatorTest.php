<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Services\Database\Constraints\ConstraintValidator;
use DcyphrDigital\Helpers\Services\Database\Constraints\TableConstraints;
use PHPUnit\Framework\TestCase;

class ConstraintValidatorTest extends TestCase
{
    /**
     * Mirrors a `members` table: unique (brand_id, email), (brand_id, crm_id), (brand_id, website_id).
     */
    private function membersTable(): TableConstraints
    {
        $column = fn (string $typeName, string $type, bool $nullable = false, bool $autoIncrement = false) => [
            'type_name' => $typeName,
            'type' => $type,
            'nullable' => $nullable,
            'auto_increment' => $autoIncrement,
        ];

        return new TableConstraints(
            table: 'members',
            columns: [
                'id' => $column('bigint', 'bigint unsigned', autoIncrement: true),
                'brand_id' => $column('bigint', 'bigint unsigned'),
                'crm_id' => $column('bigint', 'bigint unsigned', nullable: true),
                'website_id' => $column('bigint', 'bigint unsigned', nullable: true),
                'email' => $column('varchar', 'varchar(255)'),
                'first_name' => $column('varchar', 'varchar(10)', nullable: true),
                'gender' => $column('enum', "enum('Male','Female','Not Provided')"),
                'created_at' => $column('timestamp', 'timestamp', nullable: true),
                'updated_at' => $column('timestamp', 'timestamp', nullable: true),
            ],
            uniqueIndexes: [
                ['id'],
                ['brand_id', 'email'],
                ['brand_id', 'crm_id'],
                ['brand_id', 'website_id'],
            ],
        );
    }

    private function validator(array $existingRows = []): ConstraintValidator
    {
        return new ConstraintValidator(
            table: $this->membersTable(),
            findExistingRows: fn (array $columns, array $tuples) => $existingRows,
        );
    }

    private function member(array $overrides = []): array
    {
        return array_merge([
            'brand_id' => 1,
            'crm_id' => 1,
            'website_id' => 1,
            'email' => 'one@test.com',
            'first_name' => 'One',
            'gender' => 'Male',
        ], $overrides);
    }

    public function test_valid_items_pass_through(): void
    {
        $items = [
            $this->member(),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'two@test.com']),
        ];

        $result = $this->validator()->validate($items, ['brand_id', 'crm_id']);

        $this->assertSame($items, $result['valid']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_rejects_null_in_a_not_null_column(): void
    {
        $result = $this->validator()->validate([$this->member(['email' => null])]);

        $this->assertSame([], $result['valid']);
        $this->assertSame(['email cannot be null'], $result['rejected'][0]['reasons']);
    }

    public function test_rejects_values_outside_the_enum_case_insensitively(): void
    {
        $result = $this->validator()->validate([
            $this->member(['gender' => 'female']),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'two@test.com', 'gender' => 'Unknown']),
        ]);

        $this->assertCount(1, $result['valid']);
        $this->assertSame(
            ["gender 'Unknown' must be one of: Male, Female, Not Provided"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_rejects_non_integer_and_negative_unsigned_values(): void
    {
        $result = $this->validator()->validate([
            $this->member(['website_id' => 'abc']),
            $this->member(['crm_id' => 2, 'website_id' => -5, 'email' => 'two@test.com']),
            $this->member(['crm_id' => 3, 'website_id' => '7', 'email' => 'three@test.com']),
        ]);

        $this->assertSame([3], array_column($result['valid'], 'crm_id'));
        $this->assertSame(["website_id 'abc' must be an integer"], $result['rejected'][0]['reasons']);
        $this->assertSame(["website_id '-5' cannot be negative"], $result['rejected'][1]['reasons']);
    }

    public function test_an_empty_string_in_a_nullable_integer_column_becomes_null(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => '', 'email' => 'one@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => '', 'email' => 'two@test.com']),
        ]);

        $this->assertSame([null, null], array_column($result['valid'], 'website_id'));
        $this->assertSame([], $result['rejected']);
    }

    public function test_an_empty_string_in_a_not_null_integer_column_is_rejected(): void
    {
        $result = $this->validator()->validate([$this->member(['brand_id' => ''])]);

        $this->assertSame(["brand_id '' must be an integer"], $result['rejected'][0]['reasons']);
    }

    public function test_rejects_strings_longer_than_the_column(): void
    {
        $result = $this->validator()->validate([$this->member(['first_name' => 'Christopher'])]);

        $this->assertSame(['first_name is longer than 10 characters'], $result['rejected'][0]['reasons']);
    }

    public function test_rejects_every_new_item_that_shares_a_unique_value(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'alice@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'bob@test.com']),
            $this->member(['crm_id' => 3, 'website_id' => 43, 'email' => 'carol@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertSame([3], array_column($result['valid'], 'crm_id'));
        $this->assertSame([1, 2], array_map(fn ($rejection) => $rejection['item']['crm_id'], $result['rejected']));
        $this->assertSame(
            ["brand_id '1', website_id '42' is used by more than one item in this batch"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_compares_emails_case_insensitively_within_the_batch(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => 1, 'email' => 'same@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'SAME@test.com']),
        ]);

        $this->assertSame([], $result['valid']);
        $this->assertCount(2, $result['rejected']);
    }

    public function test_keeps_the_update_and_rejects_the_new_item_when_they_share_a_value(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'a@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'new@test.com']),
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'a@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
        $this->assertSame(
            ["brand_id '1', website_id '42' is used by more than one item in this batch"],
            $result['rejected'][0]['reasons'],
        );
    }

    /**
     * Two stored members (crm_id 1 and 2) that are both being updated to website_id 42.
     */
    private function updateBothStoredMembersToTheSameWebsiteId(array $first, array $second): array
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'a@test.com'],
            ['brand_id' => 1, 'crm_id' => 2, 'website_id' => 2, 'email' => 'b@test.com'],
        ]);

        return $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'a@test.com', ...$first]),
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'b@test.com', ...$second]),
        ], ['brand_id', 'crm_id']);
    }

    public function test_keeps_the_last_update_when_no_recency_column_decides(): void
    {
        $result = $this->updateBothStoredMembersToTheSameWebsiteId([], []);

        $this->assertSame([2], array_column($result['valid'], 'crm_id'));
        $this->assertSame(
            ["brand_id '1', website_id '42' is duplicated by a newer item in this batch"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_keeps_the_update_with_the_newest_created_at(): void
    {
        $result = $this->updateBothStoredMembersToTheSameWebsiteId(
            ['created_at' => '2026-03-01 10:00:00'],
            ['created_at' => '2026-01-01 10:00:00'],
        );

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
    }

    public function test_uses_updated_at_when_created_at_is_the_same(): void
    {
        $result = $this->updateBothStoredMembersToTheSameWebsiteId(
            ['created_at' => '2026-01-01', 'updated_at' => '2026-05-01'],
            ['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-02-01'],
        );

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
    }

    public function test_uses_id_when_the_timestamps_are_the_same_or_missing(): void
    {
        $result = $this->updateBothStoredMembersToTheSameWebsiteId(['id' => 20], ['id' => 10]);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
    }

    public function test_an_update_with_a_created_at_is_newer_than_one_without(): void
    {
        $result = $this->updateBothStoredMembersToTheSameWebsiteId(['created_at' => '2026-01-01'], []);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
    }

    public function test_null_values_never_count_as_duplicates(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => null, 'email' => 'one@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => null, 'email' => 'two@test.com']),
        ]);

        $this->assertCount(2, $result['valid']);
    }

    public function test_rejects_a_unique_value_stored_on_another_record(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 99, 'website_id' => 77, 'email' => 'existing@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 5, 'website_id' => 77, 'email' => 'newcomer@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertSame([], $result['valid']);
        $this->assertSame(
            ["brand_id '1', website_id '77' already exists in members"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_an_update_may_keep_its_own_unique_values(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 5, 'website_id' => 77, 'email' => 'me@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 5, 'website_id' => 77, 'email' => 'ME@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertCount(1, $result['valid']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_the_stored_record_wins_over_a_new_item_in_the_same_batch(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 301, 'website_id' => 88, 'email' => 'before@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 301, 'website_id' => 88, 'email' => 'after@test.com']),
            $this->member(['crm_id' => 302, 'website_id' => 88, 'email' => 'conflict@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertSame([301], array_column($result['valid'], 'crm_id'));
        $this->assertSame(302, $result['rejected'][0]['item']['crm_id']);
    }

    public function test_a_null_match_key_is_never_the_same_record(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => null, 'website_id' => 77, 'email' => 'me@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => null, 'website_id' => 77, 'email' => 'me@test.com']),
        ], ['brand_id', 'crm_id']);

        $this->assertSame([], $result['valid']);
    }

    public function test_without_match_keys_every_stored_match_is_a_conflict(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'one@test.com'],
        ]);

        $result = $validator->validate([$this->member()]);

        $this->assertSame([], $result['valid']);
        $this->assertCount(1, $result['rejected']);
    }
}
