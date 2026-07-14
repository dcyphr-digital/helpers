<?php

namespace DcyphrDigital\Helpers\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

class UpdateSqlService
{
    public function __construct(protected Model $model) {}

    public function handle($group, $fieldsToUpdate, $alwaysUpdate, $conditionalUpdate, array $matchKeys): void
    {
        if ($group === [] || $matchKeys === []) {
            return;
        }

        $case = $this->buildCaseStatementsForGroup($group, $fieldsToUpdate, $alwaysUpdate, $conditionalUpdate, $matchKeys);

        if (! $this->caseStatementsHaveBranches($case)) {
            return;
        }

        $this->runSql($fieldsToUpdate, $case, $group, $matchKeys);
    }

    private function runSql(array $fieldsToUpdate, array $case, array $group, array $matchKeys): void
    {
        $table = $this->model->getTable();
        $grammar = $this->model->getConnection()->getQueryGrammar();
        $set = [];

        foreach ($fieldsToUpdate as $field) {
            if (! empty($case[$field])) {
                $wrappedField = $grammar->wrap($field);
                $set[] = "{$wrappedField} = CASE " . implode(' ', $case[$field]) . " ELSE {$wrappedField} END";
            }
        }

        if (! empty($set) && $this->shouldStampUpdatedAt($set)) {
            $updatedAtColumn = $grammar->wrap($this->model->getUpdatedAtColumn());
            $quotedNow = $this->model->getConnection()->getPdo()->quote(
                now()->format('Y-m-d H:i:s')
            );
            $set[] = "{$updatedAtColumn} = {$quotedNow}";
        }

        if ($set === []) {
            return;
        }

        $whereParts = [];
        foreach ($group as $item) {
            $whereParts[] = '(' . $this->buildMatchKeyCondition($item['existing'], $matchKeys) . ')';
        }
        $whereSql = implode(' OR ', array_unique($whereParts));
        $sql = 'UPDATE ' . $grammar->wrapTable($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $whereSql;
        $this->model->getConnection()->statement($sql);
    }

    /**
     * Build searched CASE branches (WHEN match_keys THEN value) per field.
     */
    private function buildCaseStatementsForGroup(
        array $group,
        array $fieldsToUpdate,
        array $alwaysUpdate,
        array $conditionalUpdate,
        array $matchKeys
    ): array {
        $case = [];

        foreach ($fieldsToUpdate as $field) {
            $case[$field] = [];
        }

        foreach ($group as $item) {
            if (! isset($item['existing']) || ! is_array($item['existing'])) {
                throw new InvalidArgumentException('Update row is missing existing match key data.');
            }

            $existing = $item['existing'];

            foreach ($fieldsToUpdate as $field) {
                if (! array_key_exists($field, $item['data'])) {
                    continue;
                }

                $newValue = $item['data'][$field];

                if (! $this->shouldUpdateField($field, $alwaysUpdate, $conditionalUpdate)) {
                    continue;
                }

                $case[$field][] = $this->caseBranchForRow($existing, $matchKeys, $newValue, $field);
            }
        }

        return $case;
    }

    private function caseBranchForRow(array $existing, array $matchKeys, mixed $newValue, ?string $field): string
    {
        $condition = $this->buildMatchKeyCondition($existing, $matchKeys);
        $quoted = $this->quoteValue($newValue, $field);
        return "WHEN {$condition} THEN {$quoted}";
    }

    private function buildMatchKeyCondition(array $row, array $matchKeys): string
    {
        $grammar = $this->model->getConnection()->getQueryGrammar();
        $parts = [];

        foreach ($matchKeys as $key) {
            $col = $grammar->wrap($key);
            if (! array_key_exists($key, $row)) {
                throw new InvalidArgumentException("Missing match key '{$key}' in existing row.");
            }
            $value = $row[$key];
            if ($value === null) {
                $parts[] = "{$col} IS NULL";

                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            $quoted = $this->model->getConnection()->getPdo()->quote($value);
            $parts[] = "{$col} = {$quoted}";
        }

        return implode(' AND ', $parts);
    }

    private function quoteValue(mixed $value, ?string $field): string
    {
        if ($field && $this->isDateField($field) && $value === '') {
            $value = null;
        }
        if ($value === null) {
            return 'NULL';
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return $this->model->getConnection()->getPdo()->quote($value);
    }

    private function caseStatementsHaveBranches(array $case): bool
    {
        foreach ($case as $branches) {
            if ($branches !== []) {
                return true;
            }
        }

        return false;
    }

    private function isDateField(string $field): bool
    {
        try {
            $type = $this->model->getConnection()
                ->getDoctrineColumn($this->model->getTable(), $field)
                ->getType()
                ->getName();

            return in_array($type, ['date', 'datetime', 'timestamp'], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function shouldUpdateField(string $field, array $alwaysUpdate, array $conditionalUpdate): bool
    {
        return in_array($field, $alwaysUpdate, true) || in_array($field, $conditionalUpdate, true);
    }

    /**
     * Bump updated_at on bulk updates when the model uses timestamps and updated_at is not
     * already driven by the CASE batch (avoids duplicating the column in SET).
     */
    private function shouldStampUpdatedAt(array $setClauses): bool
    {
        if (! $this->model->usesTimestamps()) {
            return false;
        }

        $column = $this->model->getUpdatedAtColumn();
        if ($column === null || $column === '') {
            return false;
        }

        $wrapped = $this->model->getConnection()->getQueryGrammar()->wrap($column);
        foreach ($setClauses as $clause) {
            if (str_starts_with((string) $clause, "{$wrapped} =")) {
                return false;
            }
        }

        return true;
    }
}
