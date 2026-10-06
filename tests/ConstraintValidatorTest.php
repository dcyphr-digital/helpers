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
            ["gender must be one of: Male, Female, Not Provided"],
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
        $this->assertSame(["website_id must be an integer"], $result['rejected'][0]['reasons']);
        $this->assertSame(["website_id cannot be negative"], $result['rejected'][1]['reasons']);
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

        $this->assertSame(["brand_id must be an integer"], $result['rejected'][0]['reasons']);
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
            ["(brand_id, website_id) is used by more than one item in this batch (also brand_id '1', crm_id '2')"],
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
            ["(brand_id, website_id) is used by more than one item in this batch (also brand_id '1', crm_id '1')"],
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
            ["(brand_id, website_id) is duplicated by a newer item in this batch (brand_id '1', crm_id '2')"],
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
            ["(brand_id, website_id) already exists in members (brand_id '1', crm_id '99')"],
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

    /**
     * Columns written when SynchroniseMembers updates a stored member (no website_id).
     */
    private const UPDATE_COLUMNS = ['email', 'first_name', 'gender'];

    public function test_an_update_is_not_rejected_for_a_column_it_does_not_write(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 5, 'website_id' => 77, 'email' => 'me@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 5, 'website_id' => 'not-a-number', 'email' => 'new@test.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([5], array_column($result['valid'], 'crm_id'));
        $this->assertSame([], $result['rejected']);
    }

    public function test_updates_sharing_a_value_they_do_not_write_are_all_kept(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'a@test.com'],
            ['brand_id' => 1, 'crm_id' => 2, 'website_id' => 2, 'email' => 'b@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'a@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'b@test.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([1, 2], array_column($result['valid'], 'crm_id'));
        $this->assertSame([], $result['rejected']);
    }

    public function test_an_update_may_carry_a_value_stored_on_another_record_when_it_does_not_write_it(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 5, 'website_id' => 5, 'email' => 'me@test.com'],
            ['brand_id' => 1, 'crm_id' => 6, 'website_id' => 77, 'email' => 'other@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 5, 'website_id' => 77, 'email' => 'me@test.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([5], array_column($result['valid'], 'crm_id'));
    }

    public function test_an_update_is_still_rejected_for_a_column_it_writes(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 5, 'website_id' => 5, 'email' => 'me@test.com'],
            ['brand_id' => 1, 'crm_id' => 6, 'website_id' => 6, 'email' => 'taken@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 5, 'website_id' => 5, 'email' => 'taken@test.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([], $result['valid']);
        $this->assertSame(
            ["(brand_id, email) already exists in members (brand_id '1', crm_id '6')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_updates_sharing_a_value_they_write_keep_only_the_newest(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'a@test.com'],
            ['brand_id' => 1, 'crm_id' => 2, 'website_id' => 2, 'email' => 'b@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 1, 'email' => 'shared@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'SHARED@test.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([2], array_column($result['valid'], 'crm_id'));
        $this->assertSame(1, $result['rejected'][0]['item']['crm_id']);
        $this->assertSame(
            ["(brand_id, email) is duplicated by a newer item in this batch (brand_id '1', crm_id '2')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_an_update_keeping_its_email_wins_over_a_create_with_the_same_email(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'taken@gmail.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 1, 'email' => 'taken@gmail.com', 'first_name' => 'Updated']),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'TAKEN@gmail.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
        $this->assertSame(2, $result['rejected'][0]['item']['crm_id']);
        $this->assertSame(
            ["(brand_id, email) already exists in members (brand_id '1', crm_id '1')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_an_update_changing_to_an_email_wins_over_a_create_with_the_same_email(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'old@gmail.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'taken@gmail.com']),
            $this->member(['crm_id' => 1, 'website_id' => 1, 'email' => 'taken@gmail.com']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
        $this->assertSame(2, $result['rejected'][0]['item']['crm_id']);
        $this->assertSame(
            ["(brand_id, email) is used by more than one item in this batch (also brand_id '1', crm_id '1')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_reasons_never_contain_the_item_values(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 9, 'website_id' => 9, 'email' => 'private@gmail.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 'secret-id', 'email' => 'private@gmail.com', 'gender' => 'Secret']),
            $this->member(['crm_id' => 2, 'website_id' => 2, 'email' => 'private@gmail.com']),
        ], ['brand_id', 'crm_id']);

        $reasons = implode(' ', array_merge(...array_column($result['rejected'], 'reasons')));

        $this->assertCount(2, $result['rejected']);
        $this->assertStringNotContainsString('private@gmail.com', $reasons);
        $this->assertStringNotContainsString('secret-id', $reasons);
        $this->assertStringNotContainsString('Secret', $reasons);
    }

    public function test_with_first_create_wins_only_the_first_new_item_sharing_a_value_is_kept(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'one@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'two@test.com']),
            $this->member(['crm_id' => 3, 'website_id' => 42, 'email' => 'three@test.com']),
            $this->member(['crm_id' => 4, 'website_id' => 43, 'email' => 'four@test.com']),
        ], ['brand_id', 'crm_id'], firstCreateWins: true);

        $this->assertSame([1, 4], array_column($result['valid'], 'crm_id'));
        $this->assertSame([2, 3], array_map(fn ($rejection) => $rejection['item']['crm_id'], $result['rejected']));
        $this->assertSame(
            ["(brand_id, website_id) is already used by an earlier item in this batch (brand_id '1', crm_id '1')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_with_first_create_wins_an_update_sharing_the_value_still_beats_every_new_item(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 1, 'website_id' => 1, 'email' => 'a@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'two@test.com']),
            $this->member(['crm_id' => 3, 'website_id' => 42, 'email' => 'three@test.com']),
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'a@test.com']),
        ], ['brand_id', 'crm_id'], firstCreateWins: true);

        $this->assertSame([1], array_column($result['valid'], 'crm_id'));
        $this->assertSame([2, 3], array_map(fn ($rejection) => $rejection['item']['crm_id'], $result['rejected']));
    }

    public function test_with_first_create_wins_an_item_losing_on_a_later_index_does_not_take_a_value_on_an_earlier_one(): void
    {
        // crm 2 shares the email with crm 3 and the website_id with crm 1. Checked one index at a time, crm 2 beat
        // crm 3 on the email, then lost to crm 1 on the website_id, so neither was kept
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'one@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 42, 'email' => 'shared@test.com']),
            $this->member(['crm_id' => 3, 'website_id' => 43, 'email' => 'shared@test.com']),
        ], ['brand_id', 'crm_id'], firstCreateWins: true);

        $this->assertSame([1, 3], array_column($result['valid'], 'crm_id'));
        $this->assertSame([2], array_map(fn ($rejection) => $rejection['item']['crm_id'], $result['rejected']));
        $this->assertSame(
            ["(brand_id, website_id) is already used by an earlier item in this batch (brand_id '1', crm_id '1')"],
            $result['rejected'][0]['reasons'],
        );
    }

    public function test_with_first_create_wins_an_item_clashing_with_a_stored_row_does_not_take_a_value(): void
    {
        // crm 1's website_id is stored on another member, so crm 1 is not written and crm 2 keeps the email
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 9, 'website_id' => 42, 'email' => 'stored@test.com'],
        ]);

        $result = $validator->validate([
            $this->member(['crm_id' => 1, 'website_id' => 42, 'email' => 'shared@test.com']),
            $this->member(['crm_id' => 2, 'website_id' => 43, 'email' => 'shared@test.com']),
        ], ['brand_id', 'crm_id'], firstCreateWins: true);

        $this->assertSame([2], array_column($result['valid'], 'crm_id'));
        $this->assertSame([1], array_map(fn ($rejection) => $rejection['item']['crm_id'], $result['rejected']));
    }

    public function test_a_create_is_still_checked_on_every_column(): void
    {
        $result = $this->validator()->validate([
            $this->member(['crm_id' => 5, 'website_id' => 'not-a-number']),
        ], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame([], $result['valid']);
        $this->assertCount(1, $result['rejected']);
    }

    public function test_the_rejected_update_keeps_every_column(): void
    {
        $validator = $this->validator([
            ['brand_id' => 1, 'crm_id' => 5, 'website_id' => 5, 'email' => 'me@test.com'],
        ]);
        $item = $this->member(['crm_id' => 5, 'website_id' => 'not-a-number', 'gender' => 'Unknown']);

        $result = $validator->validate([$item], ['brand_id', 'crm_id'], updateColumns: self::UPDATE_COLUMNS);

        $this->assertSame($item, $result['rejected'][0]['item']);
    }
}
