<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\SanitizesPhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SanitizesPhoneNumberTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new class {
            use SanitizesPhoneNumber;

            public function outgoing(mixed $phone, ?string $country = null): ?string
            {
                return $this->sanitizePhoneNumber($phone, $country);
            }

            public function incoming(mixed $phone, ?string $country = null): ?string
            {
                return $this->sanitizeIncomingPhoneNumber($phone, $country);
            }
        };
    }

    #[DataProvider('outgoingProvider')]
    public function test_sanitize_phone_number(mixed $input, string $country, ?string $expected): void
    {
        $this->assertSame($expected, $this->helper->outgoing($input, $country));
    }

    public static function outgoingProvider(): array
    {
        return [
            'au domestic mobile' => ['0412345678', 'Australia', '+61412345678'],
            'au domestic mobile with spaces' => ['0412 345 678', 'Australia', '+61412345678'],
            'au already e164' => ['+61412345678', 'Australia', '+61412345678'],
            'au landline' => ['0212345678', 'Australia', null],
            'au empty' => ['', 'Australia', null],
            'au null' => [null, 'Australia', null],
            'nz domestic mobile' => ['0212345678', 'New Zealand', '+64212345678'],
            'nz short valid' => ['021234567', 'New Zealand', '+6421234567'],
            'nz already e164' => ['+64212345678', 'New Zealand', '+64212345678'],
            'au mobile containing a zero' => ['0410345678', 'Australia', '+61410345678'],
            'au e164 mobile containing a zero' => ['+61410345678', 'Australia', '+61410345678'],
            'au e164 landline' => ['+61212345678', 'Australia', null],
            'nz e164 mobile containing a zero' => ['+64210345678', 'New Zealand', '+64210345678'],
            'other country left as is' => ['+15551234567', 'United States', '+15551234567'],
        ];
    }

    #[DataProvider('invalidOutgoingProvider')]
    public function test_sanitize_phone_number_returns_null_for_invalid_length(mixed $input, string $country): void
    {
        $this->assertNull($this->helper->outgoing($input, $country));
    }

    public static function invalidOutgoingProvider(): array
    {
        return [
            'au too short' => ['041234567', 'Australia'],
            'au too long' => ['04123456789', 'Australia'],
            'nz too short' => ['02123456', 'New Zealand'],
            'nz too long' => ['0212345678901', 'New Zealand'],
        ];
    }

    #[DataProvider('incomingProvider')]
    public function test_sanitize_incoming_phone_number(mixed $input, string $country, ?string $expected): void
    {
        $this->assertSame($expected, $this->helper->incoming($input, $country));
    }

    public static function incomingProvider(): array
    {
        return [
            'au e164 to domestic' => ['+61412345678', 'Australia', '0412345678'],
            'au e164 to domestic with spaces' => ['+61 412 345 678', 'Australia', '0412345678'],
            'au already domestic' => ['0412345678', 'Australia', '0412345678'],
            'au domestic with spaces' => ['1232 311 222', 'Australia', '1232311222'],
            'au empty' => ['', 'Australia', null],
            'nz e164 to domestic' => ['+64212345678', 'New Zealand', '0212345678'],
            'nz short valid' => ['+642123456', 'New Zealand', '02123456'],
            'other country left as is' => ['+15551234567', 'United States', '+15551234567'],
        ];
    }

    #[DataProvider('invalidIncomingProvider')]
    public function test_sanitize_incoming_phone_number_returns_null_for_invalid_length(mixed $input, string $country): void
    {
        $this->assertNull($this->helper->incoming($input, $country));
    }

    public static function invalidIncomingProvider(): array
    {
        return [
            'au too short' => ['+614123456', 'Australia'],
            'nz too short' => ['+6421234', 'New Zealand'],
            'nz too long' => ['+642123456789', 'New Zealand'],
        ];
    }
}
