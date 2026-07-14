<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Collection;

class Deduplicator
{
    /**
     * Group by the given keys and keep one item per group.
     * When $sortKey is set, keeps the item with the greatest sort value.
     * When $sortKey is null, keeps the last item in each group as-is.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $groupKeys
     * @return list<array<string, mixed>>
     */
    public static function latestByKeys(array $items, array $groupKeys, ?string $sortKey = null): array
    {
        return collect($items)
            ->groupBy(function (array $item) use ($groupKeys): string {
                return collect($groupKeys)
                    ->map(fn (string $key): string => (string) ($item[$key] ?? ''))
                    ->implode('|');
            })
            ->map(function (Collection $group) use ($sortKey): array {
                return $group
                    ->when(
                        $sortKey,
                        fn (Collection $grouped) => $grouped->sortBy(
                            fn (array $item): string => (string) ($item[$sortKey] ?? '')
                        ),
                    )
                    ->last();
            })
            ->values()
            ->all();
    }
}
