<?php

namespace DcyphrDigital\Helpers\Support;

trait WebsitePersonHelpers
{
    public function websiteSourceFromReferenceId(mixed $referenceId): string
    {
        if ($referenceId === null) {
            return 'Website';
        }

        return match ((int) $referenceId) {
            351105  => 'TP Adelaide',
            351107  => 'TP Brisbane',
            316542  => 'TP Perth',
            368421  => 'TP Tullamarine',
            526068  => 'TP Sydney ECQ',
            470225  => 'TP Manawa Bay',
            default => 'Website',
        };
    }
}
