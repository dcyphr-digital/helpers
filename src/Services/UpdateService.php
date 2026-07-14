<?php

namespace DcyphrDigital\Helpers\Services;

use DcyphrDigital\Helpers\Services\Rules\RulesProvider;
use Illuminate\Database\Eloquent\Model;

class UpdateService
{
    use HelperService;

    protected UpdateSqlService $updateSqlService;

    public function __construct(protected Model $model)
    {
        $this->updateSqlService = resolve(UpdateSqlService::class, ['model' => $this->model]);
    }

    public function handle(array $toUpdate, array $reliable, array $defaultValuesForReliableKeys, array $matchKeys, ?array $rules = []): void
    {
        // Group updates by a hash of reliable key values
        $groups = [];
        foreach ($toUpdate as $item) {
            $key = $this->generateLookupKey(item: $item['data'], matchKeys: $matchKeys);
            $groups[$key][] = $item;
        }

        // Process each group
        foreach ($groups as $group) {
            $group = $this->overwriteWithDefaultValue(defaultValuesForReliableKeys: $defaultValuesForReliableKeys, group: $group);
            $this->bulkUpdate(group: $group, reliable: $reliable, matchKeys: $matchKeys, rules: $rules);
        }
    }

    private function overwriteWithDefaultValue(array $defaultValuesForReliableKeys, array $group): array
    {
        foreach ($defaultValuesForReliableKeys as $field => $value) {
            $group[0]['data'][$field] = $value;
        }

        return $group;
    }

    private function bulkUpdate(array $group, array $reliable, array $matchKeys, ?array $rules = []): void
    {
        // Fields to always update (reliableKeys minus matchKeys)
        $alwaysUpdate = array_diff($reliable, $matchKeys);

        $conditionalUpdate = $this->conditionalUpdateKeys(rules: $rules, group: $group, matchKeys: $matchKeys, alwaysUpdate: $alwaysUpdate);
        $fieldsToUpdate = array_merge($alwaysUpdate, $conditionalUpdate);

        $this->updateSqlService->handle(
            group: $group,
            fieldsToUpdate: $fieldsToUpdate,
            alwaysUpdate: $alwaysUpdate,
            conditionalUpdate: $conditionalUpdate,
            matchKeys: $matchKeys
        );
    }

    private function conditionalUpdateKeys(?array $rules, array $group, array $matchKeys, array $alwaysUpdate): array
    {
        $conditionalUpdate = [];

        foreach ($rules ?? [] as $rule) {
            $ruleProvider = resolve(RulesProvider::class, ['rule' => $rule]);
            $ruleInstance = $ruleProvider->execute();

            if (is_null($ruleInstance)) {
                continue;
            }

            $conditionalUpdate = array_merge($conditionalUpdate, (array) $ruleInstance->handle($group, $matchKeys, $alwaysUpdate));
        }

        return array_keys($conditionalUpdate);
    }
}
