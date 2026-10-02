<?php

namespace DcyphrDigital\Helpers\Services\Database\Constraints;

use Illuminate\Database\Eloquent\Model;

/**
 * The column definitions and unique indexes of a database table.
 *
 * Table structure does not change while a process runs, so it is read from the database
 * once per table and cached in memory (queue workers pick up schema changes on restart).
 */
class TableConstraints
{
    /** @var array<string, array<string, array{type_name: string, type: string, nullable: bool, auto_increment: bool}>> */
    private static array $columnsCache = [];

    /** @var array<string, list<list<string>>> */
    private static array $uniqueIndexesCache = [];

    /**
     * @param  array<string, array{type_name: string, type: string, nullable: bool, auto_increment: bool}>  $columns  keyed by column name
     * @param  list<list<string>>  $uniqueIndexes  column lists of every unique index (primary key included)
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $uniqueIndexes,
    ) {}

    public static function fromModel(Model $model): self
    {
        return new self($model->getTable(), self::columnsFor($model), self::uniqueIndexesFor($model));
    }

    /**
     * @return array<string, array{type_name: string, type: string, nullable: bool, auto_increment: bool}>
     */
    public static function columnsFor(Model $model): array
    {
        return self::$columnsCache[self::cacheKey($model)] ??= self::readColumns($model);
    }

    /**
     * @return list<list<string>>
     */
    public static function uniqueIndexesFor(Model $model): array
    {
        return self::$uniqueIndexesCache[self::cacheKey($model)] ??= self::readUniqueIndexes($model);
    }

    /**
     * Forget the cached structure, e.g. after running migrations in the same process.
     */
    public static function flushCache(): void
    {
        self::$columnsCache = [];
        self::$uniqueIndexesCache = [];
    }

    private static function cacheKey(Model $model): string
    {
        $connection = $model->getConnection();

        return $connection->getName().'|'.$connection->getDatabaseName().'|'.$model->getTable();
    }

    private static function readColumns(Model $model): array
    {
        $columns = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
            $columns[$column['name']] = [
                'type_name' => strtolower($column['type_name']),
                'type' => $column['type'],
                'nullable' => (bool) $column['nullable'],
                'auto_increment' => (bool) $column['auto_increment'],
            ];
        }

        return $columns;
    }

    private static function readUniqueIndexes(Model $model): array
    {
        $uniqueIndexes = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable()) as $index) {
            if ($index['unique']) {
                $uniqueIndexes[] = $index['columns'];
            }
        }

        return $uniqueIndexes;
    }
}
