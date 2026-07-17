<?php

namespace DcyphrDigital\Helpers\Enums;

enum PlatformName: string
{
    case Klaviyo = 'klaviyo';
    case TripleWhale = 'triple_whale';
    case Sendgrid = 'sendgrid';
    case Crm = 'crm';
    case Website = 'website';
    case WebsiteUI = 'website_ui';
    case Stock = 'stock';
    case Bazaar = 'bazaar';
    case CampaignMonitor = 'campaign_monitor';
    case PreferenceCentre = 'preference_centre';
    case DataSftp = 'data_sftp';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
