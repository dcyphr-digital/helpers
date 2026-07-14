<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Collection;

class Deduplicator
{
    /**
     * Keep the latest row per brand_id + email, ordered by source_created_date.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function latestByBrandAndEmail(array $items): array
    {
        return static::latestByKeys($items, ['brand_id', 'email'], 'source_created_date');
    }

    /**
     * Group by the given keys and keep the item with the greatest sort column.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $groupKeys
     * @return list<array<string, mixed>>
     */
    public static function latestByKeys(array $items, array $groupKeys, string $sortKey = 'source_created_date'): array
    {
        return collect($items)
            ->groupBy(function (array $item) use ($groupKeys): string {
                return collect($groupKeys)
                    ->map(fn (string $key): string => (string) ($item[$key] ?? ''))
                    ->implode('|');
            })
            ->map(
                fn (Collection $group): array => $group
                    ->sortBy(fn (array $item): string => (string) ($item[$sortKey] ?? ''))
                    ->last(),
            )
            ->values()
            ->all();
    }
}
