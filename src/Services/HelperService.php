<?php

namespace DcyphrDigital\Helpers\Services;

use Exception;
use Illuminate\Support\Str;

trait HelperService
{
    public function findExistingRecordsByMatchKeys(array $items, array $matchKeys, array $additionalSelectColumns = []): array
    {
        if (empty($matchKeys)) {
            return [];
        }

        $normalizedMatchTuples = [];

        collect($items)->each(function ($item) use ($matchKeys, &$normalizedMatchTuples) {
            $tuple = [];
            foreach ($matchKeys as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new Exception("Match key field '{$field}' is not set in the data");
                }
                $tuple[$field] = $this->normalizeMatchKeyValue($item[$field]);
            }
            $normalizedMatchTuples[$this->generateLookupKey($tuple, $matchKeys)] = $tuple;
        });

        $query = $this->model->newQuery();

        $query->where(function ($outerQuery) use ($normalizedMatchTuples, $matchKeys) {
            foreach (array_values($normalizedMatchTuples) as $i => $tuple) {
                $whereMethod = $i === 0 ? 'where' : 'orWhere';
                $outerQuery->{$whereMethod}(function ($tupleQuery) use ($tuple, $matchKeys) {
                    foreach ($matchKeys as $field) {
                        $value = $tuple[$field];
                        if ($value === null) {
                            $tupleQuery->whereNull($field);
                        } else {
                            $tupleQuery->where($field, $value);
                        }
                    }
                });
            }
        });

        $selectColumns = array_values(array_unique(array_merge(
            $matchKeys,
            $additionalSelectColumns
        )));

        $records = $query->select($selectColumns)->get()->toArray();

        foreach ($records as $i => $record) {
            foreach ($matchKeys as $field) {
                if (array_key_exists($field, $record)) {
                    $records[$i][$field] = $this->normalizeMatchKeyValue($record[$field]);
                }
            }
        }

        return $records;
    }

    public function createExistingMap(array $existingRecords, array $matchKeys): array
    {
        $map = [];
        foreach ($existingRecords as $record) {
            $key = $this->generateLookupKey($record, $matchKeys);
            $map[$key] = $record;
        }

        return $map;
    }

    public function generateLookupKey(array $item, array $matchKeys): string
    {
        $keyData = [];

        foreach ($matchKeys as $field) {
            if (array_key_exists($field, $item)) {
                $keyData[$field] = $this->normalizeMatchKeyValue($item[$field]);
            }
        }
        ksort($keyData);

        return md5(serialize($keyData));
    }

    private function normalizeMatchKeyValue(mixed $value): mixed
    {
        return is_string($value) ? Str::lower($value) : $value;
    }

    public function validateMatchKeys(array $matchKeys): void
    {
        if (empty($matchKeys)) {
            return;
        }

        $tableName = $this->model->getTable();
        $connection = $this->model->getConnection();
        $databaseName = $connection->getDatabaseName();

        $primaryKey = $this->model->getKeyName();

        $uniqueConstraints = $this->getUniqueConstraints($connection, $databaseName, $tableName);

        foreach ($matchKeys as $matchKey) {
            if (! $this->isValidMatchKey($matchKey, $primaryKey, $uniqueConstraints)) {
                throw new Exception(
                    "Match key '{$matchKey}' is not a primary key or part of a unique constraint. " .
                        'This could lead to unintended data modifications.'
                );
            }
        }

        if (count($matchKeys) > 1) {
            if (! $this->isUniqueCombination($matchKeys, $uniqueConstraints, $primaryKey)) {
                throw new Exception(
                    'Multiple match keys [' . implode(', ', $matchKeys) . '] do not form a unique constraint. ' .
                        'This could lead to unintended data modifications.'
                );
            }
        }
    }

    private function getUniqueConstraints($connection, string $databaseName, string $tableName): array
    {
        $constraints = $connection->select('
            SELECT
                INDEX_NAME,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND NON_UNIQUE = 0
            GROUP BY INDEX_NAME
        ', [$databaseName, $tableName]);

        $uniqueConstraints = [];
        foreach ($constraints as $constraint) {
            $uniqueConstraints[] = explode(',', $constraint->COLUMNS);
        }

        return $uniqueConstraints;
    }

    private function isUniqueCombination(array $matchKeys, array $uniqueConstraints, string $primaryKey): bool
    {
        // Sort match keys for comparison
        sort($matchKeys);

        // Check if the combination matches any unique constraint
        foreach ($uniqueConstraints as $constraint) {
            sort($constraint);
            if ($matchKeys === $constraint) {
                return true;
            }
        }
        // This is a more permissive approach that allows flexibility
        $allValid = true;
        foreach ($matchKeys as $matchKey) {
            if ($matchKey !== $primaryKey) {
                // Check if it's part of any unique constraint
                $foundInConstraint = false;
                foreach ($uniqueConstraints as $constraint) {
                    if (in_array($matchKey, $constraint)) {
                        $foundInConstraint = true;
                        break;
                    }
                }
                if (! $foundInConstraint) {
                    $allValid = false;
                    break;
                }
            }
        }

        return $allValid;
    }

    private function isValidMatchKey(string $matchKey, string $primaryKey, array $uniqueConstraints): bool
    {
        // Check if it's the primary key
        if ($matchKey === $primaryKey) {
            return true;
        }

        // Check if it's part of any unique constraint
        foreach ($uniqueConstraints as $constraint) {
            if (in_array($matchKey, $constraint)) {
                return true;
            }
        }

        return false;
    }
}
