<?php

namespace DcyphrDigital\Helpers\Support;

use App\Models\Bazaar\Brand as BazaarBrand;
use App\Models\PreferenceCentre\Brand as PreferenceCentreBrand;
use App\Models\Bazaar\Marketplace as BazaarMarketplace;
use App\Models\Brand;
use App\Models\Crm\Brand as CrmBrand;
use App\Models\Loyalty\Brand as LoyaltyBrand;
use Carbon\Carbon;
use DcyphrDigital\Helpers\Enums\PlatformName;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

trait CommandFiltersTrait
{
    protected array $filters;

    protected ?Brand $brand = null;

    protected ?CrmBrand $crmBrand = null;

    protected const int DEFAULT_SUB_DAYS = 3;

    /**
     * The datetimes from_date and to_date accept, besides a date only (Y-m-d). ISO 8601 may end in Z for UTC.
     */
    private const array RANGE_DATETIME_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d\\TH:i:s',
        'Y-m-d\\TH:i',
        'Y-m-d\\TH:i:sP',
        'Y-m-d\\TH:iP',
    ];

    /**
     * Split a comma-separated string into trimmed, non-empty parts.
     *
     * @return list<string>
     */
    protected function parseCommaSeparatedList(string $value): array
    {
        $parts = array_map(fn (string $p) => trim($p), explode(',', $value));

        return array_values(array_filter($parts, fn (string $p) => $p !== ''));
    }

    /**
     * @throws Throwable
     */
    private function checkPlatforms(): void
    {
        $this->filters ??= [];

        $incomingSegments = $this->parseIncomingPlatformArgument();

        if ($incomingSegments === []) {
            $this->fail('At least one incoming platform is required (comma-separated list allowed, e.g. crm,website).');
        }

        foreach ($incomingSegments as $segment) {
            $incomingPlatformName = PlatformName::tryFrom($segment);

            if ($incomingPlatformName === null) {
                $available = implode(', ', PlatformName::values());
                $this->fail("Incoming platform '{$segment}' not found, available platforms: {$available}");
            }
        }

        $outgoingSegments = $this->parseOutgoingPlatformArgument();

        if ($outgoingSegments === []) {
            $this->fail('At least one outgoing platform is required (comma-separated list allowed, e.g. crm,klaviyo).');
        }

        foreach ($outgoingSegments as $segment) {
            $outgoingPlatformName = PlatformName::tryFrom($segment);

            if ($outgoingPlatformName === null) {
                $available = implode(', ', PlatformName::values());
                $this->fail("Outgoing platform '{$segment}' not found, available platforms: {$available}");
            }
        }
    }

    private function parseIncomingPlatformArgument(): array
    {
        $raw = '';

        if ($this->hasOption('incoming_platform_names')) {
            $opt = $this->option('incoming_platform_names');
            if ($opt !== null && $opt !== '') {
                $raw = (string) $opt;
            }
        }

        if ($raw === '' && $this->hasOption('incoming_platform_name')) {
            $opt = $this->option('incoming_platform_name');
            if ($opt !== null && $opt !== '') {
                $raw = (string) $opt;
            }
        }

        return $this->parseCommaSeparatedList($raw);
    }

    private function parseOutgoingPlatformArgument(): array
    {
        $raw = '';

        if ($this->hasArgument('outgoing_platform_names')) {
            $arg = $this->argument('outgoing_platform_names');
            if ($arg !== null && $arg !== '') {
                $raw = (string) $arg;
            }
        }

        if ($raw === '' && $this->hasArgument('outgoing_platform_name')) {
            $arg = $this->argument('outgoing_platform_name');
            if ($arg !== null && $arg !== '') {
                $raw = (string) $arg;
            }
        }

        return $this->parseCommaSeparatedList($raw);
    }

    /**
     * Whether the command must find a CRM brand matching its brand. Without one, crm_brand_id is null
     * and CRM queries filtered by it would cover every brand, so commands reading CRM data override this.
     */
    protected function requiresCrmBrand(): bool
    {
        return true;
    }

    private function setupBrands(): void
    {
        $brandName = $this->argument('brand_name');

        $this->brand = Brand::where('name', $brandName)->first()
            ?? $this->fail("Brand '{$brandName}' not found.");

        $this->crmBrand = class_exists(CrmBrand::class)
            ? CrmBrand::where('brand', $this->brand->name)->first()
            : null;

        if ($this->crmBrand === null && $this->requiresCrmBrand()) {
            $this->fail("No CRM brand found for brand '{$this->brand->name}'.");
        }

        $this->filters['brand_id'] = $this->brand->id;
        $this->filters['crm_brand_id'] = $this->crmBrand?->id;

        /** @var list<array{platform: string, brand_id: int}> $incomingPlatforms */
        $incomingPlatforms = $this->buildIncomingPlatformsWithBrandIds();
        $this->filters['incoming_platforms'] = $incomingPlatforms;

        /** @var list<array{platform: string, brand_id: int}> $outgoingPlatforms */
        $outgoingPlatforms = $this->buildOutgoingPlatformsWithBrandIds();
        $this->filters['outgoing_platforms'] = $outgoingPlatforms;
    }

    private function buildIncomingPlatformsWithBrandIds(): array
    {
        $incoming = [];

        foreach ($this->parseIncomingPlatformArgument() as $segment) {
            $platform = PlatformName::tryFrom($segment);
            if ($platform === null) {
                throw new InvalidArgumentException('Invalid incoming platform name');
            }

            $incoming[] = [
                'platform' => 'in-'.$platform->value,
                'brand_id' => $this->incomingBrandIdForPlatform($platform),
            ];
        }

        return $incoming;
    }

    /**
     * @return list<array{platform: string, brand_id: int}>
     */
    private function buildOutgoingPlatformsWithBrandIds(): array
    {
        $outgoing = [];

        foreach ($this->parseOutgoingPlatformArgument() as $segment) {
            $platform = PlatformName::tryFrom($segment);
            if ($platform === null) {
                throw new InvalidArgumentException('Invalid outgoing platform name');
            }

            $outgoing[] = [
                'platform' => 'out-'.$platform->value,
                'brand_id' => $this->outgoingBrandIdForPlatform($platform),
            ];
        }

        return $outgoing;
    }

    private function incomingBrandIdForPlatform(PlatformName $platform): int
    {
        return match ($platform) {
            PlatformName::Website                                              => (int) $this->brand->configuration->website->brand_id,
            PlatformName::Crm                                                  => $this->crmBrand->id,
            PlatformName::Klaviyo, PlatformName::Sendgrid, PlatformName::Stock => $this->brand->id,
            PlatformName::Bazaar                                               => $this->resolveBazaarBrandId(),
            PlatformName::PreferenceCentre                                     => $this->resolvePCBrandId(),
            PlatformName::Loyalty                                              => $this->resolveLoyaltyBrandId(),
            PlatformName::WebsiteUI                                            => 0, // for website_ui we don't need a brand_id so it's always 0'
            default                                                            => throw new InvalidArgumentException('Invalid incoming platform name'),
        };
    }

    private function outgoingBrandIdForPlatform(PlatformName $platform): int
    {
        return match ($platform) {
            PlatformName::Klaviyo, PlatformName::TripleWhale, PlatformName::Sendgrid, PlatformName::Stock, PlatformName::Shopify, PlatformName::Iconic => $this->brand->id,
            PlatformName::Crm, PlatformName::DataSftp                                                     => $this->crmBrand->id,
            default                                                                                       => throw new InvalidArgumentException('Invalid outgoing platform name'),
        };
    }

    private function handleDateFilters(): void
    {
        $this->filters ??= [];

        // Safely get options - only access if they exist in command signature
        $hasFromDate = $this->hasOption('from_date');
        $hasToDate = $this->hasOption('to_date');
        $hasSubDays = $this->hasOption('sub_days');

        $fromDate = $hasFromDate ? $this->option('from_date') : null;
        $toDate = $hasToDate ? $this->option('to_date') : null;
        $subDays = $hasSubDays ? $this->option('sub_days') : null;

        // Treat empty CLI options as "not provided"
        $fromDate = $fromDate !== '' ? $fromDate : null;
        $toDate = $toDate !== '' ? $toDate : null;

        // Check if user is trying to use date range (at least one date option provided)
        $isUsingDateRange = $fromDate !== null || $toDate !== null;

        // Check for invalid combinations
        if ($subDays !== null && $subDays !== '' && $isUsingDateRange) {
            $this->fail('sub_days cannot be used with from_date or to_date. Use either sub_days OR date range (from_date/to_date)');
        }

        // If using date range, both from_date and to_date must be provided
        if ($isUsingDateRange) {
            if ($fromDate === null || $toDate === null) {
                $this->fail('Both from_date and to_date must be provided when using date range. Use either sub_days OR both from_date and to_date');
            }

            try {
                $from = $this->parseRangeBoundary($fromDate, 'from');
                $to = $this->parseRangeBoundary($toDate, 'to');
            } catch (Throwable $e) {
                $this->fail('Invalid from_date or to_date. Use Y-m-d, Y-m-d H:i, Y-m-d H:i:s or ISO 8601 (e.g. 2026-10-07T10:00:00+11:00).');
            }

            // `to` must be strictly after `from` (date-only boundaries use start/end of day, so one day is a valid range)
            if ($to->lte($from)) {
                $this->fail('Invalid date range: to_date must be after from_date (got from_date='.$fromDate.', to_date='.$toDate.').');
            }
            $this->filters['date_range'] = [
                'from' => $from,
                'to'   => $to,
            ];

            return;
        }

        // Handle sub_days option or default
        $days = $this->parseSubDays($subDays);
        $this->filters['date_range'] = [
            'from' => Carbon::now()->subDays($days)->startOfDay(),
            'to'   => Carbon::now()->endOfDay(),
        ];
    }

    /**
     * The days to look back: a whole number of 0 or more, or DEFAULT_SUB_DAYS when not given (an empty option counts
     * as not given, as for the dates). Anything else fails the command: cast to int, "abc" was 0 (today only) and
     * "-5" started the range 5 days ahead, so the command synchronised nothing and still reported success.
     */
    private function parseSubDays(mixed $subDays): int
    {
        $subDays = trim((string) $subDays);

        if ($subDays === '') {
            return self::DEFAULT_SUB_DAYS;
        }

        if (! ctype_digit($subDays)) {
            $this->fail("Invalid sub_days '{$subDays}': use a whole number of days, 0 or more.");
        }

        return (int) $subDays;
    }

    /**
     * Parse a CLI date or datetime for a range boundary.
     *
     * - Date-only `Y-m-d`: `from` uses start of day, `to` uses end of day (inclusive calendar-day range).
     * - A datetime in one of RANGE_DATETIME_FORMATS: that exact instant. Without an offset it is app time; with one
     *   (e.g. +11:00 or Z) it is converted to app time.
     *
     * Each value must read back as it was written, so an impossible date or time (2026-02-30 10:00, 25:00) fails
     * instead of rolling over. Words Carbon::parse() would take (tomorrow, +1 year) fail too, as a range given in
     * them would depend on when the command runs.
     */
    private function parseRangeBoundary(string $value, string $boundary): Carbon
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $parsed = Carbon::createFromFormat('Y-m-d', $value);

            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Invalid date-only value');
            }

            return $boundary === 'from'
                ? $parsed->copy()->startOfDay()
                : $parsed->copy()->endOfDay();
        }

        // UTC written as Z, e.g. 2026-10-07T10:00:00Z
        $value = preg_replace('/Z$/', '+00:00', $value);

        foreach (self::RANGE_DATETIME_FORMATS as $format) {
            try {
                // ! sets the parts the format leaves out (e.g. the seconds of Y-m-d H:i) to 0, not to now
                $parsed = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                // The same instant in the app timezone: queries bind a Carbon as its wall time without converting it,
                // and the dates they compare it to are stored in the app timezone
                return $parsed->setTimezone(Carbon::now()->getTimezone());
            }
        }

        throw new InvalidArgumentException('Invalid datetime value');
    }

    private function setupLog(): void
    {
        $this->filters['should_log'] = $this->parseYesNoOption('log');
    }

    /**
     * A yes/no option, in any letter case: yes, y, true or 1 is yes; no, n, false or 0 is no. Given without a value
     * (e.g. --log) it is yes; not given, it is its default. Anything else fails the command, as a typo (yse) or an
     * empty value would otherwise quietly count as no.
     */
    protected function parseYesNoOption(string $name): bool
    {
        $value = $this->option($name);

        // Given on the command line without a value, e.g. --log
        if ($value === null && $this->input->hasParameterOption('--'.$name)) {
            return true;
        }

        $normalised = Str::lower(trim((string) $value));

        if (in_array($normalised, ['yes', 'y', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalised, ['no', 'n', 'false', '0'], true) || $value === null) {
            return false;
        }

        $this->fail("Invalid {$name} '{$value}': use Yes or No.");
    }

    private function setupIncomingBrandFilters(): void
    {
        $this->filters ??= [];

        $this->brand = Brand::where('name', $this->argument('brand_name'))->firstOrFail();
        $this->filters['brand_id'] = $this->brand->id;
        $this->filters['brand_name'] = $this->brand->name;
        $this->filters['incoming_platforms'] = $this->buildIncomingPlatformsWithBrandIds();
    }

    private function setupMarketplaceNameFilter(): void
    {
        $this->filters['marketplace_id'] = $this->resolveMarketplace()->id;
        $this->filters['marketplace_name'] = Str::lower($this->resolveMarketplace()->name);
    }

    private function resolveBazaarBrandId(): int
    {
        return (int) BazaarBrand::query()
            ->where('name', $this->brand?->name ?? $this->argument('brand_name'))
            ->firstOrFail()
            ->id;
    }

    private function resolveMarketplace(): BazaarMarketplace
    {
        return BazaarMarketplace::query()
            ->where('name', $this->argument('marketplace_name'))
            ->firstOrFail();
    }

    private function resolvePCBrandId(): int
    {
        return (int) PreferenceCentreBrand::query()
            ->where('name', $this->brand?->name ?? $this->argument('brand_name'))
            ->firstOrFail()
            ->id;
    }

    private function resolveLoyaltyBrandId(): int
    {
        return (int) LoyaltyBrand::query()
            ->where('name', $this->brand?->name ?? $this->argument('brand_name'))
            ->firstOrFail()
            ->id;
    }
}
