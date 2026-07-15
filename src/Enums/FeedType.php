<?php

namespace DcyphrDigital\Helpers\Enums;

enum FeedType: string
{
    case GoogleShopping = 'google_shopping';
    case Facebook = 'facebook';

    case Athos = 'athos';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
