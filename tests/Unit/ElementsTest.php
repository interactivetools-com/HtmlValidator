<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which elements get through: the allowlist, custom elements, the elements with a switch
 * (style, iframe, forms), and everything refused with no switch.
 *
 * Code: element-not-allowed. Attribute values are covered in AttributesTest, UrlsTest and CssTest.
 */
final class ElementsTest extends HtmlValidatorTestCase
{
    //region Allowlist

    #[DataProvider('allowedElementProvider')]
    public function testAllowedElement(string $element): void
    {
        $this->assertAccepts("<$element>x</$element>");
    }

    public static function allowedElementProvider(): array
    {
        $cases = [];
        foreach (HtmlValidator::rules()['elements'] as $element) {
            $cases[$element] = [$element];
        }
        return $cases;
    }

    public function testTagNamesAreCaseInsensitive(): void
    {
        $this->assertAccepts('<P><B>x</B></P>');
        $this->assertRejects('<SCRIPT>x</SCRIPT>', 'element-not-allowed', '<SCRIPT>');
    }

    #[DataProvider('customElementProvider')]
    public function testCustomElement(string $element, bool $allowed): void
    {
        $html = "<$element>x</$element>";
        if ($allowed) {
            $this->assertAccepts($html);
        } else {
            $this->assertRejects($html, 'element-not-allowed', "<$element>");
        }
    }

    public static function customElementProvider(): array
    {
        return [
            'hyphenated name'        => ['my-widget', true],
            'digits and dots'        => ['x-1.0_b-c', true],
            'hyphen at the end'      => ['widget-', true],
            'no hyphen'              => ['widget', false],
            'namespace prefix'       => ['o:p', false],
            'less-than in the name'  => ['a<b', false],
        ];
    }

    public function testUnknownAttributesOnAllowedElementsPass(): void
    {
        $this->assertAccepts('<div class="a" id="b" data-x="1" aria-label="c" role="d" contenteditable draggable="true" unknown="e">x</div>');
    }

    //endregion
    //region Refused With No Switch

    #[DataProvider('refusedElementProvider')]
    public function testRefusedElement(string $tag): void
    {
        $this->assertRejects("<p>a</p>$tag<p>b</p>", 'element-not-allowed', $tag);
    }

    public static function refusedElementProvider(): array
    {
        return [
            'script'           => ['<script>'],
            'script with src'  => ['<script src="https://example.com/x.js">'],
            'noscript'         => ['<noscript>'],
            'template'         => ['<template>'],
            'svg'              => ['<svg>'],
            'svg with onload'  => ['<svg onload="alert(1)">'],
            'math'             => ['<math>'],
            'object'           => ['<object data="x.swf">'],
            'embed'            => ['<embed src="x.swf">'],
            'applet'           => ['<applet>'],
            'html'             => ['<html>'],
            'head'             => ['<head>'],
            'body'             => ['<body onload="x">'],
            'title'            => ['<title>'],
            'meta'             => ['<meta http-equiv="refresh" content="0;url=https://example.com">'],
            'base'             => ['<base href="https://example.com/">'],
            'link'             => ['<link rel="stylesheet" href="https://example.com/x.css">'],
            'frameset'         => ['<frameset>'],
            'frame'            => ['<frame src="x">'],
            'plaintext'        => ['<plaintext>'],
            'xmp'              => ['<xmp>'],
            'noembed'          => ['<noembed>'],
            'noframes'         => ['<noframes>'],
            'listing is fine'  => ['<bgsound src="x">'],
        ];
    }

    public function testRefusedElementReportsOnceAndSkipsItsAttributes(): void
    {
        $result = HtmlValidator::check('<script onload="x" src="javascript:1"></script>');
        $this->assertCount(1, $result->errors);
        $this->assertSame('element-not-allowed', $result->errors[0]->code);
    }

    public function testEndTagsAreNotChecked(): void
    {
        $this->assertAccepts('<p>x</script></svg></p>');
    }

    public function testDoctypeIsRefused(): void
    {
        $this->assertRejects('<!DOCTYPE html><p>x</p>', 'element-not-allowed', '<!DOCTYPE html>');
        $this->assertRejects('<!doctype html>', 'element-not-allowed', '<!doctype html>');
    }

    public function testCommentsPass(): void
    {
        $this->assertAccepts('<!-- <script>alert(1)</script> --><p>x</p>');
        $this->assertAccepts('<!--[if IE]><p>x</p><![endif]-->');
        $this->assertAccepts('<?php echo 1; ?>');   // a bogus comment to a browser
    }

    public function testTextIsNotChecked(): void
    {
        $this->assertAccepts('&lt;script&gt;alert(1)&lt;/script&gt; and a lone < here');
        $this->assertAccepts('<1-x> and <-x> are text, since a tag name starts with a letter');
    }

    //endregion
    //region Elements With a Switch

    public function testStyleElementFollowsAllowStyles(): void
    {
        $this->assertAccepts('<style>p { color: red }</style>');
        $this->withSettings(['allowStyles' => false], function () {
            $this->assertRejects('<style>p { color: red }</style>', 'element-not-allowed', '<style>');
        });
    }

    public function testIframeFollowsAllowEmbeds(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/x"></iframe>';
        $this->assertAccepts($html);
        $this->withSettings(['allowEmbeds' => false], function () use ($html) {
            $this->assertRejects($html, 'element-not-allowed', '<iframe src="https://www.youtube.com/embed/x">');
        });
    }

    #[DataProvider('formElementProvider')]
    public function testFormElementsFollowAllowForms(string $element): void
    {
        $html = "<$element>x</$element>";
        $this->assertRejects($html, 'element-not-allowed', "<$element>");
        $this->withSettings(['allowForms' => true], fn() => $this->assertAccepts($html));
    }

    public static function formElementProvider(): array
    {
        $cases = [];
        foreach (HtmlValidator::rules()['formElements'] as $element) {
            $cases[$element] = [$element];
        }
        return $cases;
    }

    //endregion
}
