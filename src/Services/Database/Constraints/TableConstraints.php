<?php

namespace DcyphrDigital\Helpers\Services\Database\Constraints;

use Illuminate\Database\Eloquent\Model;

/**
 * The column definitions and unique indexes of a database table.
 */
class TableConstraints
{
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
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        $columns = [];
        foreach ($schema->getColumns($table) as $column) {
            $columns[$column['name']] = [
                'type_name' => strtolower($column['type_name']),
                'type' => $column['type'],
                'nullable' => (bool) $column['nullable'],
                'auto_increment' => (bool) $column['auto_increment'],
            ];
        }

        $uniqueIndexes = [];
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['unique']) {
                $uniqueIndexes[] = $index['columns'];
            }
        }

        return new self($table, $columns, $uniqueIndexes);
    }
}
