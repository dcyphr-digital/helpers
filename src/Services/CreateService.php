<?php

namespace DcyphrDigital\Helpers\Services;

use Illuminate\Database\Eloquent\Model;

class CreateService
{
    public function __construct(protected Model $model) {}

    public function handle(array $toCreate): void
    {
        $rows = collect($toCreate)->map(function (array $row) {
            return $this->withTimestampsForInsert($row);
        });

        $this->model->insert($rows->toArray());
    }

    private function withTimestampsForInsert(array $row): array
    {
        if (! $this->model->usesTimestamps()) {
            return $row;
        }

        $now = now()->format($this->model->getDateFormat());

        if (($col = $this->model->getCreatedAtColumn()) && (! array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '')) {
            $row[$col] = $now;
        }

        if (($col = $this->model->getUpdatedAtColumn()) && (! array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '')) {
            $row[$col] = $now;
        }

        return $row;
    }
}
