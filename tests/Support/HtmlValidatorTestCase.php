<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Support;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Result;
use Itools\HtmlValidator\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the validator's Unit and Integration suites.
 *
 * Conventions:
 * - Inputs are written inline, so each test shows the whole fragment it checks
 * - assertRejects() checks that the code is in the error list, not that it is the only
 *   error, since one bad tag often breaks two rules; ResultTest pins exact lists
 * - Tests that change a switch or limit go through withSettings(), which restores the
 *   defaults afterwards so one test cannot change what the next one sees
 * - Failure messages list every violation the validator reported, one per line
 */
abstract class HtmlValidatorTestCase extends TestCase
{
    use SharedTestHelpers;

    //region Assertions

    /** Assert check($html) passes. Returns the Result. */
    protected function assertAccepts(string $html): Result
    {
        $result = HtmlValidator::check($html);
        $this->assertTrue($result->ok, "Expected the HTML to be accepted, got:\n" . self::describe($result));
        return $result;
    }

    /**
     * Assert check($html) reports a violation with code $code and, when given, exactly
     * $detail. Returns that violation so the test can check its message too.
     */
    protected function assertRejects(string $html, string $code, ?string $detail = null): Violation
    {
        $result = HtmlValidator::check($html);
        foreach ($result->errors as $violation) {
            if ($violation->code === $code && ($detail === null || $violation->detail === $detail)) {
                $this->addToAssertionCount(1);   // a match is the assertion; without this PHPUnit flags the test as risky
                return $violation;
            }
        }
        $wanted = $detail === null ? $code : "$code with detail '$detail'";
        $this->fail("Expected $wanted, got:\n" . self::describe($result));
    }

    /** One line per violation, "code: detail", or "(no errors)". For failure messages. */
    protected static function describe(Result $result): string
    {
        if ($result->ok) {
            return '(no errors)';
        }
        return implode("\n", array_map(fn(Violation $violation) => "$violation->code: $violation->detail", $result->errors));
    }

    //endregion
    //region Settings

    /**
     * Run $fn with the given static properties of HtmlValidator set, then put every one back.
     *
     *     $this->withSettings(['allowForms' => true], fn() => $this->assertAccepts('<form></form>'));
     *
     * @param array<string, mixed> $settings property name => value
     */
    protected function withSettings(array $settings, callable $fn): mixed
    {
        $saved = [];
        foreach ($settings as $property => $value) {
            $saved[$property]          = HtmlValidator::$$property;
            HtmlValidator::$$property = $value;
        }
        try {
            return $fn();
        } finally {
            foreach ($saved as $property => $value) {
                HtmlValidator::$$property = $value;
            }
        }
    }

    //endregion
}
