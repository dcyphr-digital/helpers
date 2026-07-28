<?php

namespace DcyphrDigital\Helpers\Support;

trait Sanitise
{
    private function sanitiseText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';
        $text = str_replace("\u{FFFD}", '', $text);
        $text = preg_replace('/&reg[\s\t]/', '&reg; ', $text) ?? $text;

        return strip_tags($text);
    }
}
