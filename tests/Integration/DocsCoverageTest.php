<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Integration;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Result;
use Itools\HtmlValidator\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Every public method of HtmlValidator and every public property and constant of Result and
 * Violation appears in README.md and docs/ai-reference.md; the switches and limits (the
 * public static properties) and the rule constants appear in the AI reference. Found by
 * reflection so a new member cannot ship undocumented. Every page under docs/ is linked from
 * the README, so none can go unlisted.
 */
final class DocsCoverageTest extends TestCase
{
    /** @return iterable<string, array{string, string}> page and the text the member must appear as */
    public static function memberProvider(): iterable
    {
        $everywhere = [];
        $reference  = [];

        $validator = new ReflectionClass(HtmlValidator::class);
        foreach ($validator->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                $everywhere[] = 'HtmlValidator::' . $method->getName() . '(';
            }
        }
        foreach ($validator->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $reference[] = 'HtmlValidator::$' . $property->getName();
        }
        foreach ($validator->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $reference[] = $constant->getName();
        }

        foreach ([Result::class => '$result', Violation::class => '$violation'] as $class => $variable) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $everywhere[] = $variable . '->' . $property->getName();
            }
            foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                $everywhere[] = $reflection->getShortName() . '::' . $constant->getName();
            }
        }

        foreach (['README.md', 'docs/ai-reference.md'] as $page) {
            foreach ($everywhere as $member) {
                yield "$page $member" => [$page, $member];
            }
        }
        foreach ($reference as $member) {
            yield "docs/ai-reference.md $member" => ['docs/ai-reference.md', $member];
        }
    }

    #[DataProvider('memberProvider')]
    public function testMemberIsDocumented(string $page, string $member): void
    {
        $markdown = file_get_contents(__DIR__ . "/../../$page");
        $this->assertStringContainsString($member, $markdown, "$member is missing from $page");
    }

    public function testEveryDocsPageIsLinkedFromTheReadme(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        foreach (glob(__DIR__ . '/../../docs/*.md') as $path) {
            $name = basename($path);
            $this->assertStringContainsString("](docs/$name)", $readme, "README.md does not link to docs/$name");
        }
    }
}
