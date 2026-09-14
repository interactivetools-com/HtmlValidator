<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Integration;

use Itools\HtmlValidator\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the error codes and the reject fixtures in step: every code in
 * Violation::TEMPLATES has a fixture named after it, and every fixture names a
 * code that still exists.
 */
final class ErrorCodesTest extends TestCase
{
    private const REJECT_DIR = __DIR__ . '/../Support/fixtures/reject';

    public function testEveryCodeHasARejectFixture(): void
    {
        foreach (array_keys(Violation::TEMPLATES) as $code) {
            $this->assertFileExists(self::REJECT_DIR . "/$code-1.html", "no reject fixture for $code");
        }
    }

    public function testEveryRejectFixtureNamesAKnownCode(): void
    {
        foreach (glob(self::REJECT_DIR . '/*.html') as $path) {
            $name = basename($path);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+-\d+\.html$/', $name, "$name is not named <code>-<n>.html");
            $code = preg_replace('/-\d+\.html$/', '', $name);
            $this->assertArrayHasKey($code, Violation::TEMPLATES, "$name names a code that does not exist");
        }
    }
}
