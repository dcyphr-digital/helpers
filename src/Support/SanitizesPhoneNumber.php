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

        if (isset(self::REPLACE_OUTGOING_PHONE_CODE[$country]) && (Str::length($phoneNumber) < self::REPLACE_OUTGOING_PHONE_CODE[$country]['min_length'] || Str::length($phoneNumber) > self::REPLACE_OUTGOING_PHONE_CODE[$country]['max_length'])) {
            return null;
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

        if (isset(self::REPLACE_INCOMING_PHONE_CODE[$country]) && Str::length($phoneNumber) < self::REPLACE_INCOMING_PHONE_CODE[$country]['min_length'] || Str::length($phoneNumber) > self::REPLACE_INCOMING_PHONE_CODE[$country]['max_length']) {
            return null;
        }

        return $phoneNumber;
    }

    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);
    }
    
    protected function resolvePhoneCountry(): ?string
    {
        return $this->brand->configuration->country ?? null;
    }
}
