<?php

namespace DcyphrDigital\Helpers\Support;

trait StateFormatter
{
    public function formatState(?string $value): ?string
    {
        $value = self::clean($value);
        $state = strtolower($value);

        if (collect(['nz', 'new zealand'])->contains($state) || levenshtein('new zealand', $state) < 3) {
            return 'NZ';
        }

        if (collect(['act', 'australian capital territory'])->contains($state) || levenshtein('australian capital territory', $state) < 3) {
            return 'ACT';
        }

        if (collect(['nsw', 'new south wales'])->contains($state) || levenshtein('new south wales', $state) < 3) {
            return 'NSW';
        }

        if (collect(['nt', 'northern territory'])->contains($state) || levenshtein('northern territory', $state) < 3) {
            return 'NT';
        }

        if (collect(['qld', 'queensland'])->contains($state) || levenshtein('queensland', $state) < 3) {
            return 'QLD';
        }

        if (collect(['sa', 'south australia'])->contains($state) || levenshtein('south australia', $state) < 3) {
            return 'SA';
        }

        if (collect(['tas', 'tasmania'])->contains($state) || levenshtein('tasmania', $state) < 3) {
            return 'TAS';
        }

        if (collect(['vic', 'victoria'])->contains($state) || levenshtein('victoria', $state) < 3) {
            return 'VIC';
        }

        if (collect(['wa', 'western australia'])->contains($state) || levenshtein('western australia', $state) < 3) {
            return 'WA';
        }

        return 'Other';
    }

    public function formatPostcode(?string $value): ?string
    {
        $postcode = $value;

        if (((int) $postcode >= 200 && (int) $postcode <= 299) || ((int) $postcode >= 2600 && (int) $postcode <= 2618) || ((int) $postcode >= 2900 && (int) $postcode <= 2920)) {
            return 'ACT';
        }

        if (((int) $postcode >= 1000 && (int) $postcode <= 1999) || ((int) $postcode >= 2000 && (int) $postcode <= 2599) || ((int) $postcode >= 2619 && (int) $postcode <= 2899) || ((int) $postcode >= 2921 && (int) $postcode <= 2999)) {
            return 'NSW';
        }

        if (((int) $postcode >= 800 && (int) $postcode <= 899) || ((int) $postcode >= 900 && (int) $postcode <= 999)) {
            return 'NT';
        }

        if (((int) $postcode >= 4000 && (int) $postcode <= 4999) || ((int) $postcode >= 9000 && (int) $postcode <= 9999)) {
            return 'QLD';
        }

        if (((int) $postcode >= 5000 && (int) $postcode <= 5799) || ((int) $postcode >= 5800 && (int) $postcode <= 5999)) {
            return 'SA';
        }

        if (((int) $postcode >= 7000 && (int) $postcode <= 7799) || ((int) $postcode >= 7800 && (int) $postcode <= 7999)) {
            return 'TAS';
        }

        if (((int) $postcode >= 3000 && (int) $postcode <= 3999) || ((int) $postcode >= 8000 && (int) $postcode <= 8999)) {
            return 'VIC';
        }

        if (((int) $postcode >= 6000 && (int) $postcode <= 6797) || ((int) $postcode >= 6800 && (int) $postcode <= 6999)) {
            return 'WA';
        }

        return 'Other';
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value, ' "\'');
        $converted = iconv('UTF-8', 'UTF-8//IGNORE', $trimmed);

        return $converted === false ? null : $converted;
    }
}
