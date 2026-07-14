<?php

namespace DcyphrDigital\Helpers\Support;

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Illuminate\Support\Facades\Log;

trait LogHandling
{
    private function logHandling(string $level, string $message, array $data): void
    {
        if (app()->environment('local') || app()->environment('testing')) {
            Log::$level($message, $data);

            return;
        }

        Bugsnag::notifyError($level, $message, function ($report) use ($data) {
            $report->setMetaData($data);
        });
    }
}
