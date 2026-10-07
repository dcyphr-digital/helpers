<?php

namespace DcyphrDigital\Helpers\Support;

use Carbon\Carbon;

trait ParsesDateRangeBoundaries
{
    /**
     * A boundary of a date range, as a query compares it: a date without a time (Y-m-d) covers its whole day, so
     * 'from' is its start and 'to' its end; a datetime (e.g. --from_date="2026-10-01 14:30", or a Carbon) keeps its
     * exact time.
     *
     * @param  'from'|'to'  $boundary
     */
    protected function dateRangeBoundary(mixed $value, string $boundary): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $date = Carbon::parse(trim($value));

            return $boundary === 'from' ? $date->startOfDay() : $date->endOfDay();
        }

        return Carbon::parse($value);
    }
}
