# dcyphr-digital/helpers

Shared Laravel helpers used across Dcyphr projects (CSV import cleaning, AU state/postcode, brand+email dedupe).

One change here → every app that requires this package gets it on the next `composer update`.

## Install (local path — recommended while developing)

In each Laravel app `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/dcyphr-digital",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "dcyphr-digital/helpers": "@dev"
    }
}
```

Then:

```bash
composer update dcyphr-digital/helpers
```

With `symlink: true`, edits in `packages/dcyphr-digital` are live in every linked app (no reinstall).

## Install (private Git — for servers / CI)

In each app:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:dcyphr-digital/helpers.git"
        }
    ],
    "require": {
        "dcyphr-digital/helpers": "^1.0"
    }
}
```

Tag releases (`1.0.0`, `1.1.0`, …). Apps pick up changes with `composer update dcyphr-digital/helpers`.

## Usage

### Import helpers (replace duplicated `ImportHelpers` traits)

```php
use Dcyphr\Helpers\Imports\ImportHelpers;

class Persons implements ToCollection
{
    use ImportHelpers;

    public function collection(Collection $rows): void
    {
        $email = $this->normalizeEmail($row['person_loc_email'] ?? '');
        // same as: Str::lower($this->clean(...))

        $state = $this->workoutState($row['state'] ?? null, $row['postcode'] ?? null);
        $phone = $this->cleanPhone($row['phone'] ?? null);
    }
}
```

### Deduplicate latest row per brand + email

```php
use Dcyphr\Helpers\Support\Deduplicator;

$items = Deduplicator::latestByBrandAndEmail($items);

// or generic keys:
$items = Deduplicator::latestByKeys($items, ['brand_id', 'email'], 'source_created_date');
```

### Publish config (optional)

```bash
php artisan vendor:publish --tag=dcyphr-helpers-config
```

## What’s included

| API | Purpose |
|-----|---------|
| `clean()` | Trim quotes/whitespace, strip invalid UTF-8 |
| `normalizeEmail()` | `clean` + lowercase |
| `cleanPhone()` | AU mobile/landline normalisation |
| `workoutState()` / `checkPostcode()` | AU (+ NZ) state from name/postcode |
| `workoutGender()` | Male / Female / Not Provided |
| `createDate()` | Import date string parsing |
| `Deduplicator::latestByBrandAndEmail()` | Keep newest row per brand+email |

## Dev

```bash
composer install
./vendor/bin/phpunit
```
