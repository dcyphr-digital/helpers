<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\DescribesDatabaseErrors;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DescribesDatabaseErrorsTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new class {
            use DescribesDatabaseErrors;

            public function describe(QueryException $e, string $record = 'record'): string
            {
                return $this->describeDatabaseError($e, $record);
            }
        };
    }

    /**
     * A QueryException as MySQL raises it: errorInfo holds the SQLSTATE, the MySQL error code and its message.
     */
    private static function queryException(int $code, string $message): QueryException
    {
        $pdoException = new PDOException("SQLSTATE[23000]: {$message}");
        $pdoException->errorInfo = ['23000', $code, $message];

        return new QueryException('mysql', 'insert into `members` (`email`) values (?)', ['anna@test.com'], $pdoException);
    }

    #[DataProvider('errorProvider')]
    public function test_describes_the_error_by_its_column_or_index(int $code, string $message, string $expected): void
    {
        $this->assertSame($expected, $this->helper->describe(self::queryException($code, $message), 'member'));
    }

    public static function errorProvider(): array
    {
        return [
            'duplicate, MySQL 8 key' => [1062, "Duplicate entry '5-anna@test.com' for key 'members.members_brand_id_email_unique'", 'another member already has this value (unique index members_brand_id_email_unique)'],
            'duplicate, older MySQL key' => [1062, "Duplicate entry '5-920001' for key 'members_brand_id_website_id_unique'", 'another member already has this value (unique index members_brand_id_website_id_unique)'],
            'null' => [1048, "Column 'email' cannot be null", 'email cannot be empty'],
            'too long' => [1406, "Data too long for column 'first_name' at row 1", 'first_name is too long'],
            'out of range' => [1264, "Out of range value for column 'erp_id' at row 1", 'erp_id is out of range'],
            'wrong type' => [1366, "Incorrect integer value: 'abc' for column 'website_id' at row 1", 'website_id has a value of the wrong type'],
            'wrong type, column named in full' => [1366, "Incorrect integer value: 'abc' for column `loyalty`.`members`.`website_id` at row 1", 'website_id has a value of the wrong type'],
            'not allowed' => [1265, "Data truncated for column 'gender' at row 1", 'gender is not one of the allowed values'],
            'other error' => [1213, 'Deadlock found when trying to get lock; try restarting transaction', 'the database rejected the member'],
        ];
    }

    public function test_never_includes_the_rejected_value(): void
    {
        $reason = $this->helper->describe(self::queryException(1062, "Duplicate entry '5-anna@test.com' for key 'members.members_brand_id_email_unique'"));

        $this->assertStringNotContainsString('anna@test.com', $reason);
    }

    public function test_names_the_record_as_a_record_by_default(): void
    {
        $this->assertSame('the database rejected the record', $this->helper->describe(self::queryException(1213, 'Deadlock found')));
    }
}
