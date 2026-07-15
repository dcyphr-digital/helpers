<?php

namespace DcyphrDigital\Helpers\Support;

use App\Enums\PlatformName;
use App\Models\Bazaar\Brand as BazaarBrand;
use App\Models\Bazaar\Marketplace as BazaarMarketplace;
use App\Models\Brand;
use App\Models\Crm\Brand as CrmBrand;
use Carbon\Carbon;
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

    private function setupBrands(): void
    {
        $this->brand = Brand::where('name', $this->argument('brand_name'))->firstOrFail();
        $this->crmBrand = CrmBrand::where('brand', $this->brand->name)->firstOrFail();
        $this->filters['brand_id'] = $this->brand->id;
        $this->filters['crm_brand_id'] = $this->crmBrand->id;

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
            PlatformName::WebsiteUI                                            => 0, // for website_ui we don't need a brand_id so it's always 0'
            default                                                            => throw new InvalidArgumentException('Invalid incoming platform name'),
        };
    }

    private function outgoingBrandIdForPlatform(PlatformName $platform): int
    {
        return match ($platform) {
            PlatformName::Klaviyo, PlatformName::TripleWhale, PlatformName::Sendgrid, PlatformName::Stock => $this->brand->id,
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
                $this->fail('Invalid from_date or to_date. Use Y-m-d, or a datetime (e.g. Y-m-d H:i:s, or ISO 8601).');
            }

            // `to` must be strictly after `from` (date-only boundaries use start/end of day on that calendar day)
            if ($to->lte($from)) {
                $this->fail('Invalid date range: to_date must be on or after from_date (got from_date='.$fromDate.', to_date='.$toDate.').');
            }
            $this->filters['date_range'] = [
                'from' => $from,
                'to'   => $to,
            ];

            return;
        }

        // Handle sub_days option or default
        $days = $subDays !== null ? (int) $subDays : self::DEFAULT_SUB_DAYS;
        $this->filters['date_range'] = [
            'from' => Carbon::now()->subDays($days)->startOfDay(),
            'to'   => Carbon::now()->endOfDay(),
        ];
    }

    /**
     * Parse a CLI date or datetime for a range boundary.
     *
     * - Date-only `Y-m-d`: `from` uses start of day, `to` uses end of day (inclusive calendar-day range).
     * - Any value with a time or timezone: parsed as an exact instant via {@see Carbon::parse()}.
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

        return Carbon::parse($value);
    }

    private function setupLog(): void
    {
        $this->filters['should_log'] = in_array(Str::lower($this->option('log')), ['yes', 'y']);
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
}
