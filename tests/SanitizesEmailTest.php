<?php

namespace DcyphrDigital\Helpers\Tests;

use DcyphrDigital\Helpers\Support\SanitizesEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SanitizesEmailTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new class {
            use SanitizesEmail;

            public function sanitize(?string $email): ?string
            {
                return $this->sanitizeEmail($email);
            }
        };
    }

    #[DataProvider('emailProvider')]
    public function test_sanitize_email(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->helper->sanitize($input));
    }

    public static function emailProvider(): array
    {
        return [
            'unchanged' => ['john@example.com', 'john@example.com'],
            'leading space' => [' john@example.com', 'john@example.com'],
            'trailing space' => ['john@example.com ', 'john@example.com'],
            'spaces both sides' => ['  john@example.com  ', 'john@example.com'],
            'leading tab' => ["\tjohn@example.com", 'john@example.com'],
            'trailing newline' => ["john@example.com\n", 'john@example.com'],
            'trailing carriage return and newline' => ["john@example.com\r\n", 'john@example.com'],
            'non-breaking space' => ["\u{00A0}john@example.com\u{00A0}", 'john@example.com'],
            'byte order mark' => ["\u{FEFF}john@example.com", 'john@example.com'],
            'lower case' => [' John.Smith@Example.COM ', 'john.smith@example.com'],
            'lower case of other letters' => ['ÉLODIE@Example.com', 'élodie@example.com'],
            'zero-width space' => ["\u{200B}john@example.com\u{200B}", 'john@example.com'],
            'zero-width non-joiner and joiner' => ["\u{200C}john@example.com\u{200D}", 'john@example.com'],
            'word joiner' => ["\u{2060}john@example.com", 'john@example.com'],
            'mixed invisible characters' => ["\u{FEFF}\u{200B} \tJohn@Example.com\u{00A0}\r\n", 'john@example.com'],
            'space inside kept' => [' john smith@example.com ', 'john smith@example.com'],
            'only whitespace' => ["  \t ", ''],
            'empty' => ['', ''],
            'null' => [null, null],
        ];
    }
}
