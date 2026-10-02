<?php

namespace DcyphrDigital\Helpers\Services\Database\Constraints;

use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Removes items that would break the table's constraints, so one bad row
 * cannot make a whole bulk insert/update fail.
 *
 * Empty strings in nullable integer columns are stored as NULL.
 *
 * Checks, in order:
 *  1. column rules: NOT NULL, enum values, integer values, varchar/char length
 *  2. unique indexes against rows already in the table (the stored row wins)
 *  3. unique indexes within the items themselves:
 *     - items that would be created are all rejected when they share a value, or with
 *       $firstCreateWins only the first of them is kept, when no update claims the value;
 *     - items that update a stored record keep only the newest (by created_at, then
 *       updated_at, then id when the table has them, otherwise the last item in the batch)
 *
 * Items that update a stored record are only checked on the columns the update writes,
 * when the caller says which those are (see validate's $updateColumns).
 *
 * Rejection reasons never contain the item's values, as they may be personal data; a unique
 * clash names the other record by its match keys instead.
 *
 * String values are compared case-insensitively, like the default MySQL collations.
 */
class ConstraintValidator
{
    private const INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    private const LENGTH_LIMITED_TYPES = ['varchar', 'char'];

    /** Columns used, in order, to decide which duplicate item is the newest. */
    private const RECENCY_COLUMNS = ['created_at', 'updated_at', 'id'];

    private const DATE_TYPES = ['date', 'datetime', 'timestamp'];

    /** @var list<array{item: array, reasons: list<string>}> */
    private array $rejected = [];

    /** @var array<int|string, true> positions of the items that update a stored record */
    private array $updatePositions = [];

    /** @var list<string>|null columns an update writes; null when it may write any column */
    private ?array $updateColumns = null;

    /** @var list<string> */
    private array $matchKeys = [];

    private bool $firstCreateWins = false;

    /**
     * @param  Closure(list<string> $columns, list<array> $tuples): list<array>  $findExistingRows
     *                                                                           Returns stored rows whose `$columns` values match any of `$tuples`.
     */
    public function __construct(
        private TableConstraints $table,
        private Closure $findExistingRows,
    ) {}

    /**
     * @param  list<string>  $matchKeys  columns that identify the same record (an existing row with
     *                                   the same match key values is the record being updated, not a conflict)
     * @param  list<array>|null  $storedRecords  stored rows matching the items' match keys, when the caller has
     *                                           already loaded them; null to load them with findExistingRows
     * @param  list<string>|null  $updateColumns  columns written when an item updates a stored record (match keys
     *                                            are always included); null when an update may write any column
     * @param  bool  $firstCreateWins  keep the first of several new items sharing a unique value instead of
     *                                 rejecting them all, so the result does not depend on how items are batched
     * @return array{valid: list<array>, rejected: list<array{item: array, reasons: list<string>}>}
     */
    public function validate(
        array $items,
        array $matchKeys = [],
        ?array $storedRecords = null,
        ?array $updateColumns = null,
        bool $firstCreateWins = false,
    ): array {
        $this->rejected = [];
        $this->matchKeys = $matchKeys;
        $this->firstCreateWins = $firstCreateWins;
        $this->updateColumns = $updateColumns === null ? null : array_values(array_unique([...$updateColumns, ...$matchKeys]));

        $items = array_map(fn (array $item) => $this->emptyStringsToNull($item), $items);
        $this->updatePositions = $this->findUpdatePositions($items, $matchKeys, $storedRecords);

        $items = array_filter(
            $items,
            fn (array $item, int|string $position) => $this->keepOrReject($item, $this->columnViolations($item, $position)),
            ARRAY_FILTER_USE_BOTH,
        );

        foreach ($this->table->uniqueIndexes as $columns) {
            // A stored row found through the match keys is always the item's own record, never a conflict
            if (! $this->isMatchKeyIndex($columns, $matchKeys)) {
                $items = $this->rejectConflictsWithExistingRows($items, $columns, $matchKeys);
            }

            $items = $this->rejectDuplicatesWithinItems($items, $columns);
        }

        return [
            'valid' => array_values($items),
            'rejected' => $this->rejected,
        ];
    }

    /**
     * An empty string cannot be stored in a nullable integer column, but it means "no value",
     * so it is stored as NULL instead of rejecting the item.
     */
    private function emptyStringsToNull(array $item): array
    {
        foreach ($item as $column => $value) {
            $definition = $this->table->columns[$column] ?? null;

            if ($value === '' && $definition !== null && $definition['nullable']
                && in_array($definition['type_name'], self::INTEGER_TYPES, true)) {
                $item[$column] = null;
            }
        }

        return $item;
    }

    /**
     * @return list<string>
     */
    private function columnViolations(array $item, int|string $position): array
    {
        $reasons = [];

        foreach ($item as $column => $value) {
            $definition = $this->table->columns[$column] ?? null;
            if ($definition === null || ! $this->writes($position, $column)) {
                continue;
            }

            if ($value === null) {
                if (! $definition['nullable'] && ! $definition['auto_increment']) {
                    $reasons[] = "{$column} cannot be null";
                }

                continue;
            }

            $reason = match (true) {
                $definition['type_name'] === 'enum' => $this->enumViolation($column, $value, $definition['type']),
                in_array($definition['type_name'], self::INTEGER_TYPES, true) => $this->integerViolation($column, $value, $definition['type']),
                in_array($definition['type_name'], self::LENGTH_LIMITED_TYPES, true) => $this->lengthViolation($column, $value, $definition['type']),
                default => null,
            };

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    private function enumViolation(string $column, mixed $value, string $type): ?string
    {
        // type looks like: enum('Yes','No')
        preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);
        $allowed = array_map(fn (string $option) => str_replace("''", "'", $option), $matches[1]);

        $isAllowed = in_array(Str::lower((string) $value), array_map([Str::class, 'lower'], $allowed), true);

        return $isAllowed ? null : "{$column} must be one of: ".implode(', ', $allowed);
    }

    private function integerViolation(string $column, mixed $value, string $type): ?string
    {
        if (is_bool($value)) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return "{$column} must be an integer";
        }

        if (str_contains(strtolower($type), 'unsigned') && (int) $value < 0) {
            return "{$column} cannot be negative";
        }

        return null;
    }

    private function lengthViolation(string $column, mixed $value, string $type): ?string
    {
        // type looks like: varchar(255)
        if (! preg_match('/\((\d+)\)/', $type, $matches)) {
            return null;
        }

        $maxLength = (int) $matches[1];

        return mb_strlen((string) $value) > $maxLength
            ? "{$column} is longer than {$maxLength} characters"
            : null;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $matchKeys
     */
    private function isMatchKeyIndex(array $columns, array $matchKeys): bool
    {
        sort($columns);
        sort($matchKeys);

        return ! empty($matchKeys) && $columns === $matchKeys;
    }

    /**
     * An item whose unique values are already stored on a different record is rejected.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $matchKeys
     */
    private function rejectConflictsWithExistingRows(array $items, array $columns, array $matchKeys): array
    {
        $tuples = [];
        foreach ($items as $position => $item) {
            $tuple = $this->writesAll($position, $columns) ? $this->uniqueTuple($item, $columns) : null;
            if ($tuple !== null) {
                $tuples[] = $tuple;
            }
        }

        if (empty($tuples)) {
            return $items;
        }

        $existingRowsByKey = [];
        foreach (($this->findExistingRows)($columns, $tuples) as $row) {
            $existingRowsByKey[$this->tupleKey($row, $columns)] = $row;
        }

        return array_filter($items, function (array $item, int|string $position) use ($columns, $matchKeys, $existingRowsByKey) {
            if (! $this->writesAll($position, $columns)) {
                return true;
            }

            $tuple = $this->uniqueTuple($item, $columns);
            $existingRow = $tuple ? ($existingRowsByKey[$this->tupleKey($tuple, $columns)] ?? null) : null;

            if ($existingRow === null || $this->isSameRecord($item, $existingRow, $matchKeys)) {
                return true;
            }

            return $this->keepOrReject($item, [
                $this->describe($columns).' already exists in '.$this->table->table.$this->identify([$existingRow]),
            ]);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * When several items share the same unique values:
     *  - every item that would be created is rejected (none of them can be trusted), or with
     *    firstCreateWins and no update sharing the value, all but the first in the batch;
     *  - of the items that update an existing record, only the newest is kept
     *    (see isNewerThan for how "newest" is decided).
     *
     * @param  list<string>  $columns
     */
    private function rejectDuplicatesWithinItems(array $items, array $columns): array
    {
        $positionsByKey = [];
        foreach ($items as $position => $item) {
            $tuple = $this->writesAll($position, $columns) ? $this->uniqueTuple($item, $columns) : null;
            if ($tuple !== null) {
                $positionsByKey[$this->tupleKey($tuple, $columns)][] = $position;
            }
        }

        $rejectReasonByPosition = [];
        foreach ($positionsByKey as $positions) {
            if (count($positions) < 2) {
                continue;
            }

            $updates = array_values(array_filter($positions, fn ($position) => isset($this->updatePositions[$position])));
            $creates = array_diff($positions, $updates);

            // An update claiming the value always wins, as a stored record does across batches
            $keptCreate = $this->firstCreateWins && empty($updates) ? array_values($creates)[0] : null;

            foreach ($creates as $position) {
                if ($keptCreate !== null) {
                    if ($position !== $keptCreate) {
                        $rejectReasonByPosition[$position] = 'is already used by an earlier item in this batch'.$this->identify([$items[$keptCreate]]);
                    }

                    continue;
                }

                $others = array_map(fn ($other) => $items[$other], array_diff($positions, [$position]));
                $rejectReasonByPosition[$position] = 'is used by more than one item in this batch'.$this->identify($others, 'also ');
            }

            $newestUpdate = $this->newestPosition($items, $updates);
            foreach ($updates as $position) {
                if ($position !== $newestUpdate) {
                    $rejectReasonByPosition[$position] = 'is duplicated by a newer item in this batch'.$this->identify([$items[$newestUpdate]]);
                }
            }
        }

        return array_filter($items, function (array $item, int|string $position) use ($columns, $rejectReasonByPosition) {
            if (! isset($rejectReasonByPosition[$position])) {
                return true;
            }

            return $this->keepOrReject($item, [
                $this->describe($columns).' '.$rejectReasonByPosition[$position],
            ]);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  list<int|string>  $positions
     */
    private function newestPosition(array $items, array $positions): int|string|null
    {
        $newest = null;

        foreach ($positions as $position) {
            if ($newest === null || ! $this->isNewerThan($items[$newest], $items[$position])) {
                $newest = $position;
            }
        }

        return $newest;
    }

    /**
     * Positions of the items whose match keys belong to a record that is already stored,
     * i.e. the items that will be updated rather than created.
     *
     * @param  list<string>  $matchKeys
     * @param  list<array>|null  $storedRecords
     * @return array<int|string, true>
     */
    private function findUpdatePositions(array $items, array $matchKeys, ?array $storedRecords): array
    {
        if (empty($matchKeys)) {
            return [];
        }

        if ($storedRecords === null) {
            $tuples = array_values(array_filter(
                array_map(fn (array $item) => $this->uniqueTuple($item, $matchKeys), $items)
            ));

            $storedRecords = empty($tuples) ? [] : ($this->findExistingRows)($matchKeys, $tuples);
        }

        $storedKeys = [];
        foreach ($storedRecords as $row) {
            $storedKeys[$this->tupleKey($row, $matchKeys)] = true;
        }

        $updatePositions = [];
        foreach ($items as $position => $item) {
            $tuple = $this->uniqueTuple($item, $matchKeys);
            if ($tuple !== null && isset($storedKeys[$this->tupleKey($tuple, $matchKeys)])) {
                $updatePositions[$position] = true;
            }
        }

        return $updatePositions;
    }

    /**
     * Whether $item is newer than $other, comparing the first of created_at, updated_at
     * and id that the table has and that differs between the two. When none of them decide,
     * $item is not newer, so the item later in the batch wins.
     */
    private function isNewerThan(array $item, array $other): bool
    {
        foreach (self::RECENCY_COLUMNS as $column) {
            if (! isset($this->table->columns[$column])) {
                continue;
            }

            $isDateColumn = in_array($this->table->columns[$column]['type_name'], self::DATE_TYPES, true);

            $comparison = $this->compareValues($item[$column] ?? null, $other[$column] ?? null, $isDateColumn);
            if ($comparison !== 0) {
                return $comparison > 0;
            }
        }

        return false;
    }

    /**
     * Compares dates, numbers or strings; a missing value counts as the oldest.
     */
    private function compareValues(mixed $a, mixed $b, bool $asDates): int
    {
        if (blank($a) || blank($b)) {
            return filled($a) <=> filled($b);
        }

        if ($asDates || $a instanceof DateTimeInterface || $b instanceof DateTimeInterface) {
            return Carbon::parse($a) <=> Carbon::parse($b);
        }

        return is_numeric($a) && is_numeric($b) ? $a <=> $b : strcmp((string) $a, (string) $b);
    }

    /**
     * The item's values for a unique index, or null when the index does not apply
     * (a column is missing, or a value is NULL — NULLs never collide in a unique index).
     *
     * @param  list<string>  $columns
     */
    private function uniqueTuple(array $item, array $columns): ?array
    {
        $tuple = [];

        foreach ($columns as $column) {
            if (! isset($item[$column]) || $item[$column] === '') {
                return null;
            }
            $tuple[$column] = $item[$column];
        }

        return $tuple;
    }

    /**
     * Items that will be created write every column; items that update a stored record
     * only write the update columns (or every column when those are not known).
     */
    private function writes(int|string $position, string $column): bool
    {
        return ! isset($this->updatePositions[$position])
            || $this->updateColumns === null
            || in_array($column, $this->updateColumns, true);
    }

    /**
     * Whether the item writes every column of a unique index; an index it leaves untouched cannot be broken by it.
     *
     * @param  list<string>  $columns
     */
    private function writesAll(int|string $position, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->writes($position, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $matchKeys
     */
    private function isSameRecord(array $item, array $existingRow, array $matchKeys): bool
    {
        if (empty($matchKeys)) {
            return false;
        }

        foreach ($matchKeys as $key) {
            // A NULL match key cannot identify a single record
            if (! isset($item[$key], $existingRow[$key])) {
                return false;
            }

            if ($this->normalise($item[$key]) !== $this->normalise($existingRow[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $columns
     */
    private function tupleKey(array $values, array $columns): string
    {
        return implode('|', array_map(fn (string $column) => $this->normalise($values[$column] ?? null), $columns));
    }

    private function normalise(mixed $value): string
    {
        return Str::lower(trim((string) $value));
    }

    /**
     * @param  list<string>  $columns
     */
    private function describe(array $columns): string
    {
        return '('.implode(', ', $columns).')';
    }

    /**
     * The match keys of the other records involved, e.g. " (brand_id '1', crm_id '2')",
     * so they can be found without logging their values; empty without match keys.
     *
     * @param  list<array>  $records
     */
    private function identify(array $records, string $prefix = ''): string
    {
        if (empty($this->matchKeys)) {
            return '';
        }

        $identities = array_map(fn (array $record) => implode(', ', array_map(
            fn (string $key) => "{$key} '".($record[$key] ?? 'null')."'",
            $this->matchKeys,
        )), $records);

        return ' ('.$prefix.implode('; ', $identities).')';
    }

    /**
     * @param  list<string>  $reasons
     */
    private function keepOrReject(array $item, array $reasons): bool
    {
        if (empty($reasons)) {
            return true;
        }

        $this->rejected[] = ['item' => $item, 'reasons' => $reasons];

        return false;
    }
}
