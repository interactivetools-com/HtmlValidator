<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Integration;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every file under tests/Support/fixtures/ is a whole fragment checked from disk:
 * accept/ files must pass, reject/ files must report the code in their name
 * (reject/<code>-<n>.html), and the TinyMCE 4 round trip must fail on every payload
 * the editor let through.
 */
final class FixturesTest extends HtmlValidatorTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';

    #[DataProvider('acceptFixtureProvider')]
    public function testAcceptFixture(string $path): void
    {
        $this->assertAccepts(file_get_contents($path));
    }

    public static function acceptFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/accept/*.html') as $path) {
            $cases[basename($path)] = [$path];
        }
        return $cases;
    }

    #[DataProvider('rejectFixtureProvider')]
    public function testRejectFixture(string $path, string $code): void
    {
        $this->assertRejects(file_get_contents($path), $code);
    }

    public static function rejectFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/reject/*.html') as $path) {
            $cases[basename($path)] = [$path, preg_replace('/-\d+\.html$/', '', basename($path))];
        }
        return $cases;
    }

    /** The editor keeps script tags, handlers, foreign content and srcdoc; each must show up under its own code. */
    #[DataProvider('tinymce4Provider')]
    public function testTinymce4RoundTripIsRejected(string $path): void
    {
        $result = HtmlValidator::check(file_get_contents($path));
        $codes  = array_column($result->errors, 'code');
        foreach (['element-not-allowed', 'event-handler', 'attribute-not-allowed', 'iframe-host'] as $code) {
            $this->assertContains($code, $codes, self::describe($result));
        }
    }

    public static function tinymce4Provider(): array
    {
        return [
            'input.html'  => [self::FIXTURES . '/tinymce4/input.html'],
            'output.html' => [self::FIXTURES . '/tinymce4/output.html'],
        ];
    }
}
