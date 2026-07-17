<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\SanitizesPhoneNumber;
use InvalidArgumentException;
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
            'au landline' => ['0212345678', 'Australia', '+61212345678'],
            'au empty' => ['', 'Australia', null],
            'au null' => [null, 'Australia', null],
            'nz domestic mobile' => ['0212345678', 'New Zealand', '+64212345678'],
            'nz short valid' => ['021234567', 'New Zealand', '+6421234567'],
            'nz already e164' => ['+64212345678', 'New Zealand', '+64212345678'],
        ];
    }

    #[DataProvider('invalidOutgoingProvider')]
    public function test_sanitize_phone_number_throws_for_invalid_input(
        mixed $input,
        string $country,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->helper->outgoing($input, $country);
    }

    public static function invalidOutgoingProvider(): array
    {
        return [
            'au too short' => [
                '041234567',
                'Australia',
                'The phone number "041234567" is not valid. Please enter a valid Australian phone number starting with 0 or +61 (e.g. 0412 345 678).',
            ],
            'au too long' => [
                '04123456789',
                'Australia',
                'The phone number "04123456789" is not valid. Please enter a valid Australian phone number starting with 0 or +61 (e.g. 0412 345 678).',
            ],
            'nz too short' => [
                '02123456',
                'New Zealand',
                'The phone number "02123456" is not valid. Please enter a valid New Zealand phone number starting with 0 or +64 (e.g. 021 234 5678).',
            ],
            'nz too long' => [
                '0212345678901',
                'New Zealand',
                'The phone number "0212345678901" is not valid. Please enter a valid New Zealand phone number starting with 0 or +64 (e.g. 021 234 5678).',
            ],
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
        ];
    }

    #[DataProvider('invalidIncomingProvider')]
    public function test_sanitize_incoming_phone_number_throws_for_invalid_input(
        mixed $input,
        string $country,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->helper->incoming($input, $country);
    }

    public static function invalidIncomingProvider(): array
    {
        return [
            'au too short' => [
                '+614123456',
                'Australia',
                'The phone number "+614123456" is not valid. Please enter a valid Australian phone number starting with 0 or +61 (e.g. 0412 345 678).',
            ],
            'nz too short' => [
                '+6421234',
                'New Zealand',
                'The phone number "+6421234" is not valid. Please enter a valid New Zealand phone number starting with 0 or +64 (e.g. 021 234 5678).',
            ],
            'nz too long' => [
                '+642123456789',
                'New Zealand',
                'The phone number "+642123456789" is not valid. Please enter a valid New Zealand phone number starting with 0 or +64 (e.g. 021 234 5678).',
            ],
        ];
    }
}
