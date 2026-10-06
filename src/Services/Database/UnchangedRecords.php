<?php

namespace DcyphrDigital\Helpers\Services\Database;

use DateTimeInterface;

/**
 * Decides which updates would not change their stored record, so CreateOrUpdateService can skip them: an unchanged
 * record is then not written and its updated_at not touched, as other code chooses records by updated_at.
 *
 * When in doubt an update counts as a change: writing an unchanged value only costs an update, skipping a changed
 * one would lose it.
 */
class UnchangedRecords
{
    use HelperService;

    /**
     * The columns to compare. Without rules, the ones the update writes: the reliable keys, less the match keys.
     * With rules, every column the items carry, less the match keys, as a rule decides from the stored values.
     *
     * @param  list<array{data: array, existing: array}>  $toUpdate
     * @return list<string>
     */
    public function columnsToCompare(array $toUpdate, array $matchKeys, array $reliable, bool $hasRules): array
    {
        if (! $hasRules) {
            return array_values(array_diff($reliable, $matchKeys));
        }

        $itemColumns = array_merge([], ...array_map(fn (array $update) => array_keys($update['data']), $toUpdate));

        return array_values(array_diff(array_unique($itemColumns), $matchKeys));
    }

    /**
     * Splits the updates into the ones that would change their stored record and the ones that would not.
     * $storedRows are the stored records, raw (without the model's casts), with the match keys and $compareColumns.
     * An update whose stored record is not among them, or with no columns to compare, counts as a change.
     *
     * @param  list<array{data: array, existing: array}>  $toUpdate
     * @param  list<array>  $storedRows
     * @return array{changed: list<array{data: array, existing: array}>, unchanged: list<array>}
     */
    public function partition(array $toUpdate, array $storedRows, array $matchKeys, array $compareColumns, array $defaultValuesForReliableKeys = []): array
    {
        if (empty($compareColumns)) {
            return ['changed' => array_values($toUpdate), 'unchanged' => []];
        }

        $storedByKey = [];
        foreach ($storedRows as $row) {
            $storedByKey[$this->generateLookupKey(item: $row, matchKeys: $matchKeys)] = $row;
        }

        $changed = [];
        $unchanged = [];
        foreach ($toUpdate as $update) {
            $stored = $storedByKey[$this->generateLookupKey(item: $update['existing'], matchKeys: $matchKeys)] ?? null;

            if ($stored !== null && ! $this->wouldChange($update['data'], $stored, $compareColumns, $defaultValuesForReliableKeys)) {
                $unchanged[] = $update['data'];

                continue;
            }

            $changed[] = $update;
        }

        return ['changed' => $changed, 'unchanged' => $unchanged];
    }

    /**
     * Whether writing $data would change $stored in any of $compareColumns. A column the item does not carry is
     * not written, so not compared, unless it has a default value, which the update writes instead.
     */
    public function wouldChange(array $data, array $stored, array $compareColumns, array $defaultValuesForReliableKeys = []): bool
    {
        foreach ($compareColumns as $column) {
            $hasDefault = array_key_exists($column, $defaultValuesForReliableKeys);

            if (! array_key_exists($column, $data) && ! $hasDefault) {
                continue;
            }

            if (! array_key_exists($column, $stored)) {
                return true;
            }

            $new = $hasDefault ? $defaultValuesForReliableKeys[$column] : $data[$column];

            if (! $this->sameStoredValue($stored[$column], $new)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $new would be stored as $stored already is. Null only equals null. A stored number (an integer or
     * float column) compares as a number, so "12" equals 12. Everything else compares as text, letter case
     * included, so a change of case is written; a text column's "0123" does not equal 123. A date equals the same
     * date at midnight ("1990-01-01" and "1990-01-01 00:00:00", as a date column returns the first).
     * Arrays and objects other than dates always count as a change.
     */
    public function sameStoredValue(mixed $stored, mixed $new): bool
    {
        if ($new instanceof DateTimeInterface) {
            $new = $new->format('Y-m-d H:i:s');
        } elseif (is_bool($new)) {
            $new = (int) $new;
        }

        if ($stored === null || $new === null) {
            return $stored === $new;
        }

        if (! is_scalar($stored) || ! is_scalar($new)) {
            return false;
        }

        if ((is_int($stored) || is_float($stored)) && is_numeric($new)) {
            return $stored == $new;
        }

        return $this->withMidnight((string) $stored) === $this->withMidnight((string) $new);
    }

    private function withMidnight(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value.' 00:00:00' : $value;
    }
}
