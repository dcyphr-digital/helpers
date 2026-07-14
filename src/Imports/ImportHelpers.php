<?php

namespace Dcyphr\Helpers\Imports;

use Carbon\Carbon;
use Illuminate\Support\Str;

trait ImportHelpers
{
    protected function createDate(mixed $dateString): ?Carbon
    {
        if ($dateString === '' || $dateString === null) {
            return null;
        }

        $dateString = (string) $dateString;

        if (Str::contains($dateString, ['EST', 'EDT'])) {
            return Carbon::createFromFormat('m/d/y g:ia T', $dateString)
                ->setTimezone($this->importTimezone());
        }

        $length = strlen($dateString);

        if ($length === 8 || $length === 9 || $length === 10) {
            return Carbon::createFromFormat('d/m/Y', $dateString)->startOfDay();
        }

        if ($length === 14 || $length === 15 || $length === 16) {
            return Carbon::createFromFormat('d/m/Y G:i', $dateString);
        }

        return Carbon::createFromFormat('d/m/Y h:i:s A', $dateString);
    }

    public function workoutGender(mixed $gender): string
    {
        $normalized = strtolower((string) $gender);

        if (collect(['female', 'f', 'woman'])->contains($normalized)) {
            return 'Female';
        }

        if (collect(['male', 'm', 'man'])->contains($normalized)) {
            return 'Male';
        }

        return 'Not Provided';
    }

    public function workoutState(mixed $state = null, mixed $postcode = null): string
    {
        $state = strtolower((string) ($state ?? ''));
        $postcode = (int) $postcode;

        if (collect(['nz', 'new zealand'])->contains($state) || levenshtein('new zealand', $state) < 3) {
            return 'NZ';
        }

        if (collect(['act', 'australian capital territory'])->contains($state) || levenshtein('australian capital territory', $state) < 3) {
            return 'ACT';
        }

        if (($postcode >= 200 && $postcode <= 299) || ($postcode >= 2600 && $postcode <= 2618) || ($postcode >= 2900 && $postcode <= 2920)) {
            return 'ACT';
        }

        if (collect(['nsw', 'new south wales'])->contains($state) || levenshtein('new south wales', $state) < 3) {
            return 'NSW';
        }

        if (($postcode >= 1000 && $postcode <= 1999) || ($postcode >= 2000 && $postcode <= 2599) || ($postcode >= 2619 && $postcode <= 2899) || ($postcode >= 2921 && $postcode <= 2999)) {
            return 'NSW';
        }

        if (collect(['nt', 'northern territory'])->contains($state) || levenshtein('northern territory', $state) < 3) {
            return 'NT';
        }

        if (($postcode >= 800 && $postcode <= 899) || ($postcode >= 900 && $postcode <= 999)) {
            return 'NT';
        }

        if (collect(['qld', 'queensland'])->contains($state) || levenshtein('queensland', $state) < 3) {
            return 'QLD';
        }

        if (($postcode >= 4000 && $postcode <= 4999) || ($postcode >= 9000 && $postcode <= 9999)) {
            return 'QLD';
        }

        if (collect(['sa', 'south australia'])->contains($state) || levenshtein('south australia', $state) < 3) {
            return 'SA';
        }

        if (($postcode >= 5000 && $postcode <= 5799) || ($postcode >= 5800 && $postcode <= 5999)) {
            return 'SA';
        }

        if (collect(['tas', 'tasmania'])->contains($state) || levenshtein('tasmania', $state) < 3) {
            return 'TAS';
        }

        if (($postcode >= 7000 && $postcode <= 7799) || ($postcode >= 7800 && $postcode <= 7999)) {
            return 'TAS';
        }

        if (collect(['vic', 'victoria'])->contains($state) || levenshtein('victoria', $state) < 3) {
            return 'VIC';
        }

        if (($postcode >= 3000 && $postcode <= 3999) || ($postcode >= 8000 && $postcode <= 8999)) {
            return 'VIC';
        }

        if (collect(['wa', 'western australia'])->contains($state) || levenshtein('western australia', $state) < 3) {
            return 'WA';
        }

        if (($postcode >= 6000 && $postcode <= 6797) || ($postcode >= 6800 && $postcode <= 6999)) {
            return 'WA';
        }

        return 'Other';
    }

    public function checkPostcode(mixed $postcode, mixed $state = null): bool
    {
        if (empty($postcode)) {
            return false;
        }

        $postcode = (string) $postcode;
        $state = strtolower((string) ($state ?? ''));

        if (collect(['nz', 'new zealand'])->contains($state)) {
            return strlen($postcode) === 3 || strlen($postcode) === 4;
        }

        $code = (int) $postcode;

        return ($code >= 200 && $code <= 299)
            || ($code >= 2600 && $code <= 2618)
            || ($code >= 2900 && $code <= 2920)
            || ($code >= 1000 && $code <= 1999)
            || ($code >= 2000 && $code <= 2599)
            || ($code >= 2619 && $code <= 2899)
            || ($code >= 2921 && $code <= 2999)
            || ($code >= 800 && $code <= 899)
            || ($code >= 900 && $code <= 999)
            || ($code >= 4000 && $code <= 4999)
            || ($code >= 9000 && $code <= 9999)
            || ($code >= 5000 && $code <= 5799)
            || ($code >= 5800 && $code <= 5999)
            || ($code >= 7000 && $code <= 7799)
            || ($code >= 7800 && $code <= 7999)
            || ($code >= 3000 && $code <= 3999)
            || ($code >= 8000 && $code <= 8999)
            || ($code >= 6000 && $code <= 6797)
            || ($code >= 6800 && $code <= 6999);
    }

    public function cleanPhone(mixed $phoneString): ?string
    {
        $phoneString = '0'.ltrim((string) $phoneString, '0');

        if (strlen($phoneString) === 10 && in_array(substr($phoneString, 0, 2), ['02', '03', '04', '07', '08'], true)) {
            return $phoneString;
        }

        return null;
    }

    public function clean(mixed $value): string
    {
        $value = trim((string) $value, " \"'");
        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $value === false ? '' : $value;
    }

    public function normalizeEmail(mixed $value): string
    {
        return Str::lower($this->clean((string) $value));
    }

    protected function importTimezone(): string
    {
        if (function_exists('config')) {
            return (string) config('dcyphr-helpers.import_timezone', 'Australia/Sydney');
        }

        return 'Australia/Sydney';
    }
}
