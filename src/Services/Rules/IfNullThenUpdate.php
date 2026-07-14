<?php

namespace DcyphrDigital\Helpers\Services\Rules;

class IfNullThenUpdate
{
    public function handle(array ...$args): array
    {
        [$group, $matchKeys, $alwaysUpdate] = $args;

        $conditionalUpdate = [];
        foreach ($group as $item) {
            foreach (array_keys($item['data']) as $field) {
                if (! in_array($field, $matchKeys, true) && ! in_array($field, $alwaysUpdate, true) && empty($item['existing'][$field])) {
                    $conditionalUpdate[$field] = true;
                }
            }
        }

        return $conditionalUpdate;
    }
}
