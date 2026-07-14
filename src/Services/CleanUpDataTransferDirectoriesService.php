<?php

namespace DcyphrDigital\Helpers\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CleanUpDataTransferDirectoriesService
{
    public function handle(string $targets, int $olderThanInDays = 3): void
    {
        foreach ($this->resolveTargets($targets) as $target) {
            $this->cleanDirectories($target['disk'], $target['directories'], $olderThanInDays);
        }
    }

    /**
     * @return list<array{disk: string, directories: Collection<int, string>}>
     */
    public function resolveTargets(string $targets): array
    {
        if (trim($targets) === '') {
            throw new InvalidArgumentException('The targets argument is required.');
        }

        $resolved = [];

        foreach (explode(',', $targets) as $target) {
            $target = trim($target);

            if ($target === '') {
                continue;
            }

            if (! str_contains($target, ':')) {
                $resolved[] = [
                    'disk'        => $target,
                    'directories' => collect(Storage::disk($target)->allDirectories()),
                ];

                continue;
            }

            [$disk, $directory] = explode(':', $target, 2);

            $resolved[] = [
                'disk'        => $disk,
                'directories' => collect(Storage::disk($disk)->allDirectories($directory))
                    ->prepend($directory)
                    ->unique()
                    ->values(),
            ];
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('The targets argument is required.');
        }

        return $resolved;
    }

    public function cleanDirectories(string $disk, Collection $directories, int $olderThanInDays): void
    {
        $directories->each(function (string $directory) use ($disk, $olderThanInDays) {
            collect(Storage::disk($disk)->files($directory))
                ->each(function (string $file) use ($disk, $olderThanInDays) {
                    if ($this->isNotOldEnoughToDelete($disk, $file, $olderThanInDays)) {
                        return;
                    }

                    Storage::disk($disk)->delete($file);
                });
        });
    }

    public function isNotOldEnoughToDelete(string $disk, string $file, int $olderThanInDays): bool
    {
        return now()->subDays($olderThanInDays)->startOfDay()->timestamp
            <= Storage::disk($disk)->lastModified($file);
    }
}
