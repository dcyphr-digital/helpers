<?php

namespace DcyphrDigital\Helpers\Services;

use DcyphrDigital\Helpers\Support\LogHandling;
use Exception;
use Illuminate\Database\Eloquent\Model;
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
     * @param  list<string>  $additionalSelectColumns  Optional DB columns to include when loading existing rows (beyond primary key + match keys).
     */
    public function handle(
        array $reliableKeys,
        array $matchKeys = [],
        array $defaultValuesForReliableKeys = [],
        ?array $rules = [],
        bool $onlyUpdate = false,
        bool $onlyCreate = false,
        array $additionalSelectColumns = [],
    ): bool {
        $this->toCreate = [];
        $this->toUpdate = [];

        try {
            // Validate matchKeys before proceeding
            $this->validateMatchKeys(matchKeys: $matchKeys);

            // Find existing records using combinations of match keys
            $this->existingRecords = $this->findExistingRecordsByMatchKeys(
                items: $this->items,
                matchKeys: $matchKeys,
                additionalSelectColumns: $additionalSelectColumns
            );

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
