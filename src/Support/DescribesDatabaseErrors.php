<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

trait DescribesDatabaseErrors
{
    /**
     * What MySQL rejected in a write, e.g. "email is too long", to log instead of the exception message.
     *
     * The message holds the rejected values (e.g. "Duplicate entry 'anna@test.com' for key ..."), which may be
     * personal data, so only the column or unique index named in it is used, never the value. $record names the
     * row in the duplicate and fallback reasons, e.g. 'member'.
     */
    protected function describeDatabaseError(QueryException $e, string $record = 'record'): string
    {
        $message = $e->errorInfo[2] ?? '';
        // MySQL quotes the column ('email'); some MariaDB messages name it in full (`db`.`members`.`email`), so the
        // last part is taken. "Column 'email' cannot be null" starts with a capital, the other messages do not
        preg_match("/column (?:'([^']+)'|(?:`[^`]*`\\.)*`([^`]+)`)/i", $message, $matches);
        $column = ($matches[1] ?? '') ?: ($matches[2] ?? '');
        // MySQL 8 prefixes the index with the table name, e.g. members.members_brand_id_email_unique
        $index = Str::match("/for key '(?:[^'.]+\\.)?([^']+)'/", $message);

        return match ($e->errorInfo[1] ?? null) {
            1062 => "another {$record} already has this value (unique index {$index})",
            1048 => "{$column} cannot be empty",
            1406 => "{$column} is too long",
            1264 => "{$column} is out of range",
            1366 => "{$column} has a value of the wrong type",
            1265 => "{$column} is not one of the allowed values",
            default => "the database rejected the {$record}",
        };
    }
}
