<?php

namespace DcyphrDigital\Helpers\Services\Database;

use DcyphrDigital\Helpers\Services\Database\Rules\RulesProvider;
use Illuminate\Database\Eloquent\Model;

class UpdateService
{
    use HelperService;

    protected UpdateSqlService $updateSqlService;

    public function __construct(protected Model $model)
    {
        $this->updateSqlService = resolve(UpdateSqlService::class, ['model' => $this->model]);
    }

    /**
     * Updates written in one statement at most, so a statement stays a reasonable size
     */
    private const int BATCH_SIZE = 500;

    /**
     * Writes the updates in batches of up to BATCH_SIZE, one UPDATE statement per batch. Each row gets its own
     * values, its own default values and its own rule result, so batching the rows does not change what is written.
     */
    public function handle(array $toUpdate, array $reliable, array $defaultValuesForReliableKeys, array $matchKeys, ?array $rules = []): void
    {
        $toUpdate = array_map(fn (array $item) => $this->withDefaultValues(item: $item, defaultValuesForReliableKeys: $defaultValuesForReliableKeys), $toUpdate);

        foreach (array_chunk($toUpdate, self::BATCH_SIZE) as $group) {
            $this->bulkUpdate(group: $group, reliable: $reliable, matchKeys: $matchKeys, rules: $rules);
        }
    }

    /**
     * The update writes the default value of a reliable key instead of the item's value, on every row.
     */
    private function withDefaultValues(array $item, array $defaultValuesForReliableKeys): array
    {
        foreach ($defaultValuesForReliableKeys as $field => $value) {
            $item['data'][$field] = $value;
        }

        return $item;
    }

    private function bulkUpdate(array $group, array $reliable, array $matchKeys, ?array $rules = []): void
    {
        // Fields to always update (reliableKeys minus matchKeys)
        $alwaysUpdate = array_values(array_diff($reliable, $matchKeys));

        // A rule decides from each row's own stored values (e.g. IfNullThenUpdate only fills a column that row has
        // empty), so it runs on each row by itself: a column empty in one row is not written to another
        $ruleInstances = $this->ruleInstances(rules: $rules);
        $rowConditionalUpdate = array_map(
            fn (array $item) => $this->conditionalUpdateKeys(ruleInstances: $ruleInstances, group: [$item], matchKeys: $matchKeys, alwaysUpdate: $alwaysUpdate),
            $group
        );
        $conditionalUpdate = array_values(array_unique(array_merge([], ...$rowConditionalUpdate)));
        $fieldsToUpdate = array_values(array_unique(array_merge($alwaysUpdate, $conditionalUpdate)));

        $this->updateSqlService->handle(
            group: $group,
            fieldsToUpdate: $fieldsToUpdate,
            alwaysUpdate: $alwaysUpdate,
            conditionalUpdate: $conditionalUpdate,
            matchKeys: $matchKeys,
            rowConditionalUpdate: $rowConditionalUpdate,
        );
    }

    private function ruleInstances(?array $rules): array
    {
        $ruleInstances = [];

        foreach ($rules ?? [] as $rule) {
            $ruleInstance = resolve(RulesProvider::class, ['rule' => $rule])->execute();

            if (! is_null($ruleInstance)) {
                $ruleInstances[] = $ruleInstance;
            }
        }

        return $ruleInstances;
    }

    private function conditionalUpdateKeys(array $ruleInstances, array $group, array $matchKeys, array $alwaysUpdate): array
    {
        $conditionalUpdate = [];

        foreach ($ruleInstances as $ruleInstance) {
            $conditionalUpdate = array_merge($conditionalUpdate, (array) $ruleInstance->handle($group, $matchKeys, $alwaysUpdate));
        }

        return array_keys($conditionalUpdate);
    }
}
