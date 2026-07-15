<?php

namespace DcyphrDigital\Helpers\Support;

trait NumberFormatter
{
    public function formatDecimal(mixed $number): float
    {
        $formatted = number_format($number, 2, '.', '');

        return (float) $formatted;
    }
}
