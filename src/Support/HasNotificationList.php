<?php

namespace DcyphrDigital\Helpers\Support;

use App\Models\Notification;

trait HasNotificationList
{
    private function getNotificationList(string $className, ?int $brandId = null): array
    {
        $notification = Notification::firstWhere('class_name', $className);

        if ($notification === null) {
            return [];
        }

        return $notification
            ->subscribersForBrand($brandId)
            ->pluck('email')
            ->toArray();
    }
}
