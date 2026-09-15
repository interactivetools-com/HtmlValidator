<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Integration;

use Itools\HtmlValidator\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the error codes, the reject fixtures and docs/errors.md in step: every code in
 * Violation::TEMPLATES has a fixture named after it, every fixture names a code that still
 * exists, and docs/errors.md has a table row for every code with its template.
 */
final class ErrorCodesTest extends TestCase
{
    private const REJECT_DIR = __DIR__ . '/../Support/fixtures/reject';
    private const ERRORS_DOC = __DIR__ . '/../../docs/errors.md';

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

    public function testEveryCodeHasARowInErrorsDoc(): void
    {
        $page = file_get_contents(self::ERRORS_DOC);
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertMatchesRegularExpression("/^\\| `$code` +\\| `(.+?)` +\\| /m", $page, "docs/errors.md has no table row for $code");
            preg_match("/^\\| `$code` +\\| `(.+?)` +\\| /m", $page, $row);
            $this->assertSame($template, $row[1], "the docs/errors.md message for $code does not match Violation::TEMPLATES");
        }
    }

    public function testErrorsDocRowsAreInTemplateOrder(): void
    {
        $page = file_get_contents(self::ERRORS_DOC);
        preg_match_all('/^\\| `([a-z0-9-]+)` +\\| `/m', $page, $rows);
        $this->assertSame(array_keys(Violation::TEMPLATES), $rows[1], 'docs/errors.md rows are not in Violation::TEMPLATES order');
    }
}
