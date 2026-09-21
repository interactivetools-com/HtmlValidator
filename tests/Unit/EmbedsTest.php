<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * <iframe src>: the host list, how a host is matched, and the URL forms that look like a
 * listed host but are not.
 *
 * Code: iframe-host.
 */
final class EmbedsTest extends HtmlValidatorTestCase
{
    #[DataProvider('allowedIframeProvider')]
    public function testAllowedIframe(string $src): void
    {
        $this->assertAccepts("<iframe src=\"$src\" width=\"560\" height=\"315\" allowfullscreen></iframe>");
    }

    public static function allowedIframeProvider(): array
    {
        return [
            'youtube'            => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'youtube nocookie'   => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'vimeo'              => ['https://player.vimeo.com/video/1?h=2'],
            'google maps'        => ['https://www.google.com/maps/embed?pb=!1m18'],
            'http'               => ['http://www.youtube.com/embed/x'],
            'protocol relative'  => ['//www.youtube.com/embed/x'],
            'uppercase host'     => ['https://WWW.YOUTUBE.COM/embed/x'],
            'host alone'         => ['https://www.youtube.com'],
            'host then query'    => ['https://www.youtube.com?v=x'],
            'host then fragment' => ['https://www.youtube.com#x'],
            'leading whitespace' => [' https://www.youtube.com/embed/x'],
            'trailing space'     => ['https://www.youtube.com/embed/x '],
            'newline inside'     => ["https://www.you\ntube.com/embed/x"],   // browsers drop it, so the host they load is youtube
            'tab inside'         => ["https://www.you\ttube.com/embed/x"],
        ];
    }

    #[DataProvider('refusedIframeProvider')]
    public function testRefusedIframe(string $html, string $detail): void
    {
        $violation = $this->assertRejects($html, 'iframe-host', $detail);
        $this->assertSame("Embedding frames from $detail is not allowed", $violation->message);
    }

    public static function refusedIframeProvider(): array
    {
        return [
            'other host'          => ['<iframe src="https://evil.example/x"></iframe>', 'https://evil.example/x'],
            'subdomain of listed' => ['<iframe src="https://evil.www.youtube.com/x"></iframe>', 'https://evil.www.youtube.com/x'],
            'listed as subdomain' => ['<iframe src="https://www.youtube.com.evil.example/x"></iframe>', 'https://www.youtube.com.evil.example/x'],
            'listed as userinfo'  => ['<iframe src="https://www.youtube.com@evil.example/x"></iframe>', 'https://www.youtube.com@evil.example/x'],
            'listed as path'      => ['<iframe src="https://evil.example/www.youtube.com/x"></iframe>', 'https://evil.example/www.youtube.com/x'],
            'backslash after'     => ['<iframe src="https://www.youtube.com\\@evil.example/x"></iframe>', 'https://www.youtube.com\\@evil.example/x'],
            'port'                => ['<iframe src="https://www.youtube.com:8080/x"></iframe>', 'https://www.youtube.com:8080/x'],
            'space inside'        => ['<iframe src="/ /www.youtube.com/../../x.html"></iframe>', '/ /www.youtube.com/../../x.html'],   // a browser keeps the space, so this is a path on the same site
            'space after host'    => ['<iframe src="https://www.youtube.com /embed/x"></iframe>', 'https://www.youtube.com /embed/x'],
            'relative'            => ['<iframe src="/uploads/page.html"></iframe>', '/uploads/page.html'],
            'no src'              => ['<iframe></iframe>', '(no src)'],
            'empty src'           => ['<iframe src=""></iframe>', '(no src)'],
            'javascript'          => ['<iframe src="javascript:alert(1)"></iframe>', 'javascript:alert(1)'],
            'data'                => ['<iframe src="data:text/html,x"></iframe>', 'data:text/html,x'],
            'only srcdoc'         => ['<iframe srcdoc="x"></iframe>', '(no src)'],
        ];
    }

    public function testCallerAddsHosts(): void
    {
        $html = '<iframe src="https://example.com/embed"></iframe>';
        $this->assertRejects($html, 'iframe-host');
        $this->withSettings(['iframeHosts' => ['www.youtube.com', 'Example.COM']], fn() => $this->assertAccepts($html));
        $this->withSettings(['iframeHosts' => []], fn() => $this->assertRejects($html, 'iframe-host'));
    }

    public function testIframeAttributesAreStillChecked(): void
    {
        $this->assertRejects('<iframe src="https://www.youtube.com/embed/x" onload="alert(1)"></iframe>', 'event-handler', 'onload');
    }

    public function testIframeContentIsRawText(): void
    {
        $this->assertAccepts('<iframe src="https://www.youtube.com/embed/x"><script>alert(1)</script></iframe>');   // text to a browser, never a script tag
    }
}
