<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

trait SanitizesPhoneNumber
{
    public const REPLACE_OUTGOING_PHONE_CODE = [
        'Australia' => [
            'search'     => '0',
            'replace'    => '+61',
            'min_length' => 12,
            'max_length' => 12,
        ],
        'New Zealand' => [
            'search'     => '0',
            'replace'    => '+64',
            'min_length' => 11,
            'max_length' => 13,
        ],
    ];

    public const REPLACE_INCOMING_PHONE_CODE = [
        'Australia' => [
            'search'     => '+61',
            'replace'    => '0',
            'min_length' => 10,
            'max_length' => 10,
        ],
        'New Zealand' => [
            'search'     => '+64',
            'replace'    => '0',
            'min_length' => 8,
            'max_length' => 10,
        ],
    ];

    protected function sanitizePhoneNumber(mixed $phoneNumber, ?string $country = null): ?string
    {
        if (empty($phoneNumber)) {
            return null;
        }

        $country ??= $this->resolvePhoneCountry();
        $originalPhoneNumber = (string) $phoneNumber;
        $phoneNumber = $this->normalizePhoneNumber($originalPhoneNumber);

        if ($country && array_key_exists($country, self::REPLACE_OUTGOING_PHONE_CODE)) {
            $phoneNumber = Str::replaceFirst(
                self::REPLACE_OUTGOING_PHONE_CODE[$country]['search'],
                self::REPLACE_OUTGOING_PHONE_CODE[$country]['replace'],
                $phoneNumber
            );
        }

        if (
            isset(self::REPLACE_OUTGOING_PHONE_CODE[$country])
            && (
                Str::length($phoneNumber) < self::REPLACE_OUTGOING_PHONE_CODE[$country]['min_length']
                || Str::length($phoneNumber) > self::REPLACE_OUTGOING_PHONE_CODE[$country]['max_length']
            )
        ) {
            throw new InvalidArgumentException($this->invalidOutgoingPhoneNumberMessage(
                $originalPhoneNumber,
                $country,
            ));
        }

        return $phoneNumber;
    }

    protected function sanitizeIncomingPhoneNumber(mixed $phoneNumber, ?string $country = null): ?string
    {
        if (empty($phoneNumber)) {
            return null;
        }

        $country ??= $this->resolvePhoneCountry();
        $originalPhoneNumber = (string) $phoneNumber;
        $phoneNumber = $this->normalizePhoneNumber($originalPhoneNumber);

        if ($country && array_key_exists($country, self::REPLACE_INCOMING_PHONE_CODE)) {
            $phoneNumber = Str::replaceFirst(
                self::REPLACE_INCOMING_PHONE_CODE[$country]['search'],
                self::REPLACE_INCOMING_PHONE_CODE[$country]['replace'],
                $phoneNumber
            );
        }

        if (
            isset(self::REPLACE_INCOMING_PHONE_CODE[$country])
            && (
                Str::length($phoneNumber) < self::REPLACE_INCOMING_PHONE_CODE[$country]['min_length']
                || Str::length($phoneNumber) > self::REPLACE_INCOMING_PHONE_CODE[$country]['max_length']
            )
        ) {
            throw new InvalidArgumentException($this->invalidIncomingPhoneNumberMessage(
                $originalPhoneNumber,
                $country,
            ));
        }

        return $phoneNumber;
    }

    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);
    }

    protected function invalidOutgoingPhoneNumberMessage(string $phoneNumber, string $country): string
    {
        return sprintf(
            'The phone number "%s" is not valid. Please enter a valid %s phone number %s.',
            $phoneNumber,
            $this->phoneNumberCountryLabel($country),
            $this->expectedOutgoingPhoneNumberFormat($country),
        );
    }

    protected function invalidIncomingPhoneNumberMessage(string $phoneNumber, string $country): string
    {
        return sprintf(
            'The phone number "%s" is not valid. Please enter a valid %s phone number %s.',
            $phoneNumber,
            $this->phoneNumberCountryLabel($country),
            $this->expectedIncomingPhoneNumberFormat($country),
        );
    }

    protected function expectedOutgoingPhoneNumberFormat(string $country): string
    {
        return match ($country) {
            'Australia' => 'starting with 0 or +61 (e.g. 0412 345 678)',
            'New Zealand' => 'starting with 0 or +64 (e.g. 021 234 5678)',
            default => 'in the correct format',
        };
    }

    protected function expectedIncomingPhoneNumberFormat(string $country): string
    {
        return match ($country) {
            'Australia' => 'starting with 0 or +61 (e.g. 0412 345 678)',
            'New Zealand' => 'starting with 0 or +64 (e.g. 021 234 5678)',
            default => 'in the correct format',
        };
    }

    protected function phoneNumberCountryLabel(string $country): string
    {
        return match ($country) {
            'Australia' => 'Australian',
            'New Zealand' => 'New Zealand',
            default => $country,
        };
    }

    protected function resolvePhoneCountry(): ?string
    {
        return $this->brand->configuration->country ?? null;
    }
}
