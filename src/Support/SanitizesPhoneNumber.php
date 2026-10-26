<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

trait SanitizesPhoneNumber
{
    public const AUSTRALIA = 'australia';
    public const NEW_ZEALAND = 'new zealand';
    public const REPLACE_OUTGOING_PHONE_CODE = [
        self::AUSTRALIA => [
            'search'     => '0',
            'replace'    => '+61',
            'min_length' => 12,
            'max_length' => 12,
        ],
        self::NEW_ZEALAND => [
            'search'     => '0',
            'replace'    => '+64',
            'min_length' => 11,
            'max_length' => 13,
        ],
    ];

    public const REPLACE_INCOMING_PHONE_CODE = [
        self::AUSTRALIA => [
            'search'     => '+61',
            'replace'    => '0',
            'min_length' => 10,
            'max_length' => 10,
        ],
        self::NEW_ZEALAND => [
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
        $country = is_string($country) ? Str::lower($country) : null;
        $originalPhoneNumber = (string) $phoneNumber;
        $phoneNumber = $this->normalizePhoneNumber($originalPhoneNumber);

        if ($this->isInvalidAustralianPhoneNumber($country, $phoneNumber)) {
            return null;
        }

        if ($country && array_key_exists($country, self::REPLACE_OUTGOING_PHONE_CODE)) {
            $phoneNumber = $this->replaceLeadingPhoneCode(
                $phoneNumber,
                self::REPLACE_OUTGOING_PHONE_CODE[$country]['search'],
                self::REPLACE_OUTGOING_PHONE_CODE[$country]['replace'],
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
        $country = is_string($country) ? Str::lower($country) : null;
        $originalPhoneNumber = (string) $phoneNumber;
        $phoneNumber = $this->normalizePhoneNumber($originalPhoneNumber);
        if ($country && array_key_exists($country, self::REPLACE_INCOMING_PHONE_CODE)) {
            $phoneNumber = $this->replaceLeadingPhoneCode(
                $phoneNumber,
                self::REPLACE_INCOMING_PHONE_CODE[$country]['search'],
                self::REPLACE_INCOMING_PHONE_CODE[$country]['replace'],
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
        return Str::lower($this->brand->configuration->country ?? null);
    }

    /**
     * Swap the prefix only when the number starts with it, so a digit in the middle of the number is never replaced.
     */
    protected function replaceLeadingPhoneCode(string $phoneNumber, string $search, string $replace): string
    {
        return Str::startsWith($phoneNumber, $search)
            ? $replace.Str::after($phoneNumber, $search)
            : $phoneNumber;
    }

    /**
     * Only Australian mobiles are accepted, written domestically (04…) or in E.164 (+614…).
     */
    protected function isInvalidAustralianPhoneNumber(?string $country, string $phoneNumber): bool
    {
        return $country === self::AUSTRALIA && ! Str::startsWith($phoneNumber, ['04', '+614']);
    }
}
