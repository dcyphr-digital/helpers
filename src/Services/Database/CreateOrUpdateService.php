<?php

namespace DcyphrDigital\Helpers\Services\Database;

use DcyphrDigital\Helpers\Services\Database\Constraints\ConstraintValidator;
use DcyphrDigital\Helpers\Services\Database\Constraints\TableConstraints;
use DcyphrDigital\Helpers\Support\LogHandling;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CreateOrUpdateService
{
    use HelperService;
    use LogHandling;

    private CreateService $createService;

    private UpdateService $updateService;

    private Model $model;

    private array $toCreate;

    private array $toUpdate;

    private array $existingRecords;

    /** @var list<array{item: array, reasons: list<string>}> */
    private array $rejected = [];

    public function __construct(protected array $items, string $model)
    {
        $this->model = resolve($model);
        $this->createService = resolve(CreateService::class, ['model' => $this->model]);
        $this->updateService = resolve(UpdateService::class, ['model' => $this->model]);
    }

    public function getToCreate(): array
    {
        return $this->toCreate;
    }

    public function getToUpdate(): array
    {
        return $this->toUpdate;
    }

    public function getExistingRecords(): array
    {
        return $this->existingRecords;
    }

    /**
     * Items removed by `validateConstraints`, each with the reasons it was rejected.
     * Only their match keys are logged; the full items are returned here.
     *
     * @return list<array{item: array, reasons: list<string>}>
     */
    public function getRejected(): array
    {
        return $this->rejected;
    }

    /**
     * @param  list<string>  $additionalSelectColumns  Optional DB columns to include when loading existing rows (beyond primary key + match keys).
     * @param  bool  $validateConstraints  Remove (and log) items that break the table's NOT NULL, enum, integer,
     *                                     length or unique constraints instead of letting the whole batch fail.
     *                                     Items that update a stored record are only checked on the columns the
     *                                     update writes (reliable + match keys), unless rules may write others.
     * @param  bool  $firstCreateWins  With validateConstraints, keep the first of several new items sharing a
     *                                 unique value instead of skipping them all.
     */
    public function handle(
        array $reliableKeys,
        array $matchKeys = [],
        array $defaultValuesForReliableKeys = [],
        ?array $rules = [],
        bool $onlyUpdate = false,
        bool $onlyCreate = false,
        array $additionalSelectColumns = [],
        bool $validateConstraints = false,
        bool $firstCreateWins = false,
    ): bool {
        $this->toCreate = [];
        $this->toUpdate = [];
        $this->existingRecords = [];
        $this->rejected = [];

        try {
            if (empty($this->items)) {
                return true;
            }

            // Validate matchKeys before proceeding
            $this->validateMatchKeys(matchKeys: $matchKeys);

            // Find existing records using combinations of match keys
            $this->existingRecords = $this->findExistingRecordsByMatchKeys(
                items: $this->items,
                matchKeys: $matchKeys,
                additionalSelectColumns: $additionalSelectColumns
            );

            if ($validateConstraints) {
                // A rule (e.g. IfNullThenUpdate) can write any column, so only narrow the checks without rules
                $this->removeItemsBreakingConstraints(
                    matchKeys: $matchKeys,
                    updateColumns: empty($rules) ? $reliableKeys : null,
                    firstCreateWins: $firstCreateWins,
                );

                if (empty($this->items)) {
                    return true;
                }
            }

            // Perform bulk create and update operations
            $this->performBulkCreateOrUpdate(
                matchKeys: $matchKeys,
                reliable: $reliableKeys,
                defaultValuesForReliableKeys: $defaultValuesForReliableKeys,
                rules: $rules,
                onlyUpdate: $onlyUpdate,
                onlyCreate: $onlyCreate
            );

            return true;
        } catch (Exception $e) {
            $this->logHandling(
                level: 'error',
                message: 'Creating or updating record failed',
                data: [
                    'model' => $this->model::class,
                    // The match keys of the items being written, so the failing batch can be found
                    'match' => array_map(fn (array $item) => Arr::only($item, $matchKeys), $this->items),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            $this->toCreate = [];
            $this->toUpdate = [];
            Log::info('Creating or updating records failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  list<string>|null  $updateColumns  columns an update writes; null when it may write any column
     */
    private function removeItemsBreakingConstraints(array $matchKeys, ?array $updateColumns, bool $firstCreateWins): void
    {
        $validator = new ConstraintValidator(
            table: TableConstraints::fromModel($this->model),
            findExistingRows: fn (array $columns, array $tuples) => $this->findRowsMatchingTuples($columns, $tuples, $matchKeys),
        );

        $result = $validator->validate(
            items: $this->items,
            matchKeys: $matchKeys,
            storedRecords: $this->existingRecords,
            updateColumns: $updateColumns,
            firstCreateWins: $firstCreateWins,
        );

        $this->items = $result['valid'];
        $this->rejected = $result['rejected'];

        if (! empty($this->rejected)) {
            Log::warning('Skipped '.count($this->rejected).' item(s) that break '.$this->model->getTable().' constraints', [
                'model' => $this->model::class,
                // Only the match keys, as the other values may be personal data
                'rejected' => array_map(fn (array $rejection) => [
                    'match' => Arr::only($rejection['item'], $matchKeys),
                    'reasons' => $rejection['reasons'],
                ], $this->rejected),
            ]);
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $tuples
     * @param  list<string>  $matchKeys
     */
    private function findRowsMatchingTuples(array $columns, array $tuples, array $matchKeys): array
    {
        return $this->model->newQuery()
            ->select(array_values(array_unique([...$columns, ...$matchKeys])))
            ->where(function ($query) use ($tuples) {
                foreach ($tuples as $tuple) {
                    $query->orWhere(fn ($tupleQuery) => $tupleQuery->where($tuple));
                }
            })
            ->toBase()
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function performBulkCreateOrUpdate(array $matchKeys, array $reliable, array $defaultValuesForReliableKeys, ?array $rules, bool $onlyUpdate, bool $onlyCreate): void
    {
        // Create lookup maps for efficient comparison
        $existingMap = $this->createExistingMap(existingRecords: $this->existingRecords, matchKeys: $matchKeys);
        $this->toCreate = [];
        $this->toUpdate = [];

        foreach ($this->items as $item) {
            $key = $this->generateLookupKey(item: $item, matchKeys: $matchKeys);
            $exists = isset($existingMap[$key]);

            if ($exists && ! $onlyCreate) {
                $this->toUpdate[] = [
                    'data'     => $item,
                    'existing' => $existingMap[$key],
                ];

                continue;
            }

            if (! $exists && ! $onlyUpdate) {
                $this->toCreate[] = $item;
            }
        }

        // Perform bulk operations
        if (! empty($this->toCreate)) {
            $this->createService->handle(toCreate: $this->toCreate);
        }

        if (! empty($this->toUpdate)) {
            $this->updateService->handle(toUpdate: $this->toUpdate, reliable: $reliable, defaultValuesForReliableKeys: $defaultValuesForReliableKeys, matchKeys: $matchKeys, rules: $rules);
        }
    }
}
