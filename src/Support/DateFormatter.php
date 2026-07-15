<?php

namespace DcyphrDigital\Helpers\Support;

use Carbon\Carbon;

trait DateFormatter
{
    public function formatDate(
        ?string $value,
        ?string $requiredFormat = null,
        ?string $currentFormat = null,
        ?string $requiredTimezone = null,
        ?string $currentTimezone = null,
        bool $returnIsoFormat = false
    ): Carbon|string|null {
        if ($value === null || (is_string($value) && $value === '')) {
            return null;
        }
        if (! $requiredTimezone) {
            $requiredTimezone = config('app.timezone');
        }

        if (! $currentTimezone) {
            $currentTimezone = config('app.timezone');
        }

        $carbon = $this->createCarbonInstance(value: $value, currentFormat: $currentFormat, currentTimezone: $currentTimezone);
        $carbon = $carbon->timezone($requiredTimezone);

        if ($requiredFormat) {
            return $carbon->format($requiredFormat);
        }

        if ($returnIsoFormat) {
            return $carbon->toIso8601String();
        }

        return $carbon;
    }

    private function createCarbonInstance(string $value, ?string $currentFormat, ?string $currentTimezone = null): Carbon
    {
        if ($currentFormat) {
            return Carbon::createFromFormat($currentFormat, $value, $currentTimezone);
        }

        return Carbon::parse($value, $currentTimezone);
    }
}
