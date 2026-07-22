<?php

namespace DcyphrDigital\Helpers\Imports;

use Carbon\Carbon;
use Illuminate\Support\Str;

trait ImportHelpers
{
    private function createDate($dateString)
    {
        if ($dateString == '' || $dateString == null) {
            return;
        }

        if (Str::contains($dateString, ['EST', 'EDT'])) {
            return Carbon::createFromFormat('m/d/y g:ia T', $dateString)->setTimezone('Australia/Sydney');
        }

        if (strlen($dateString) == 8 || strlen($dateString) == 9 || strlen($dateString) == 10) {
            return Carbon::createFromFormat('d/m/Y', $dateString)->startOfDay();
        }

        if (strlen($dateString) == 14 || strlen($dateString) == 15 || strlen($dateString) == 16) {
            return Carbon::createFromFormat('d/m/Y G:i', $dateString);
        }

        return Carbon::createFromFormat('d/m/Y h:i:s A', $dateString);
    }

    public function workoutGender($gender): string
    {
        if (collect(['female', 'f'])->contains(strtolower($gender))) {
            return 'Female';
        }
        if (collect(['male', 'm'])->contains(strtolower($gender))) {
            return 'Male';
        }

        return 'Not Provided';
    }

    public function workoutState($state = null, $postcode = null): string
    {
        if (collect(['nz', 'new zealand'])->contains(strtolower($state ?? '')) || levenshtein('new zealand', strtolower($state ?? '')) < 3) {
            return 'NZ';
        }

        if (collect(['act', 'australian capital territory'])->contains(strtolower($state ?? '')) || levenshtein('australian capital territory', strtolower($state ?? '')) < 3) {
            return 'ACT';
        }

        if (((int) $postcode >= 200 && (int) $postcode <= 299) || ((int) $postcode >= 2600 && (int) $postcode <= 2618) || ((int) $postcode >= 2900 && (int) $postcode <= 2920)) {
            return 'ACT';
        }

        if (collect(['nsw', 'new south wales'])->contains(strtolower($state ?? '')) || levenshtein('new south wales', strtolower($state ?? '')) < 3) {
            return 'NSW';
        }

        if (((int) $postcode >= 1000 && (int) $postcode <= 1999) || ((int) $postcode >= 2000 && (int) $postcode <= 2599) || ((int) $postcode >= 2619 && (int) $postcode <= 2899) || ((int) $postcode >= 2921 && (int) $postcode <= 2999)) {
            return 'NSW';
        }

        if (collect(['nt', 'northern territory'])->contains(strtolower($state ?? '')) || levenshtein('northern territory', strtolower($state ?? '')) < 3) {
            return 'NT';
        }

        if (((int) $postcode >= 800 && (int) $postcode <= 899) || ((int) $postcode >= 900 && (int) $postcode <= 999)) {
            return 'NT';
        }

        if (collect(['qld', 'queensland'])->contains(strtolower($state ?? '')) || levenshtein('queensland', strtolower($state ?? '')) < 3) {
            return 'QLD';
        }

        if (((int) $postcode >= 4000 && (int) $postcode <= 4999) || ((int) $postcode >= 9000 && (int) $postcode <= 9999)) {
            return 'QLD';
        }

        if (collect(['sa', 'south australia'])->contains(strtolower($state ?? '')) || levenshtein('south australia', strtolower($state ?? '')) < 3) {
            return 'SA';
        }

        if (((int) $postcode >= 5000 && (int) $postcode <= 5799) || ((int) $postcode >= 5800 && (int) $postcode <= 5999)) {
            return 'SA';
        }

        if (collect(['tas', 'tasmania'])->contains(strtolower($state ?? '')) || levenshtein('tasmania', strtolower($state ?? '')) < 3) {
            return 'TAS';
        }

        if (((int) $postcode >= 7000 && (int) $postcode <= 7799) || ((int) $postcode >= 7800 && (int) $postcode <= 7999)) {
            return 'TAS';
        }

        if (collect(['vic', 'victoria'])->contains(strtolower($state ?? '')) || levenshtein('victoria', strtolower($state ?? '')) < 3) {
            return 'VIC';
        }

        if (((int) $postcode >= 3000 && (int) $postcode <= 3999) || ((int) $postcode >= 8000 && (int) $postcode <= 8999)) {
            return 'VIC';
        }

        if (collect(['wa', 'western australia'])->contains(strtolower($state ?? '')) || levenshtein('western australia', strtolower($state ?? '')) < 3) {
            return 'WA';
        }

        if (((int) $postcode >= 6000 && (int) $postcode <= 6797) || ((int) $postcode >= 6800 && (int) $postcode <= 6999)) {
            return 'WA';
        }

        return 'Other';
    }

    public function checkPostcode($postcode, $state = null): bool
    {
        if (empty($postcode)) {
            return false;
        }

        if (collect(['nz', 'new zealand'])->contains(strtolower($state ?? ''))) {
            if ((strlen($postcode) == 3 || strlen($postcode) == 4)) {
                return true;
            }

            return false;
        }

        if (
            ((int) $postcode >= 200 && (int) $postcode <= 299) || ((int) $postcode >= 2600 && (int) $postcode <= 2618) || ((int) $postcode >= 2900 && (int) $postcode <= 2920) ||
            ((int) $postcode >= 1000 && (int) $postcode <= 1999) || ((int) $postcode >= 2000 && (int) $postcode <= 2599) || ((int) $postcode >= 2619 && (int) $postcode <= 2899) || ((int) $postcode >= 2921 && (int) $postcode <= 2999) ||
            ((int) $postcode >= 800 && (int) $postcode <= 899) || ((int) $postcode >= 900 && (int) $postcode <= 999) ||
            ((int) $postcode >= 4000 && (int) $postcode <= 4999) || ((int) $postcode >= 9000 && (int) $postcode <= 9999) ||
            ((int) $postcode >= 5000 && (int) $postcode <= 5799) || ((int) $postcode >= 5800 && (int) $postcode <= 5999) ||
            ((int) $postcode >= 7000 && (int) $postcode <= 7799) || ((int) $postcode >= 7800 && (int) $postcode <= 7999) ||
            ((int) $postcode >= 3000 && (int) $postcode <= 3999) || ((int) $postcode >= 8000 && (int) $postcode <= 8999) ||
            ((int) $postcode >= 6000 && (int) $postcode <= 6797) || ((int) $postcode >= 6800 && (int) $postcode <= 6999)
        ) {
            return true;
        }

        return false;
    }

    public function cleanPhone($phoneString) : false|string
    {
        $phoneString = '0'.ltrim($phoneString, 0);
        if (strlen($phoneString) === 10 && in_array(substr($phoneString, 0, 2), ['02', '03', '04', '07', '08'])) {
            return $phoneString;
        }

        return false;
    }

    public function clean($value): false|string
    {
        $value = trim($value, ' "\'');

        return iconv('UTF-8', 'UTF-8//IGNORE', $value);
    }

    public function normalizeEmail(mixed $value): string
    {
        return $this->normalize((string) ($value ?? ''));
    }

    public function normalize($value): string
    {
        return Str::lower($this->clean($value));
    }

    private function recordInvalidRow(int $rowNumber, \Throwable $e): void
    {
        $this->invalidRows[] = [
            'row_number' => $rowNumber,
            'error'      => $e->getMessage(),
        ];
    }

    private function isEmailValid(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
