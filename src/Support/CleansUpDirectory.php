<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CleansUpDirectory
{
    private function cleanDirectory(string $storagePath, int $olderThanDays = 3, ?string $nameContains = null): void
    {
        collect(
            Storage::disk('local')->files($storagePath)
        )->each(function ($file) use ($olderThanDays, $nameContains) {
            if ($nameContains && ! Str::contains($file, $nameContains)) {
                return;
            }
            if (now()->subDays($olderThanDays)->startOfDay()->timestamp > Storage::disk('local')->lastModified($file)) {
                Storage::disk('local')->delete($file);
            }
        });
    }
}
