<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

/**
 * What HtmlValidator::check() returns.
 *
 *     $result = HtmlValidator::check($html);
 *     if ($result->ok) {
 *         // store the content
 *     }
 *     foreach ($result->errors as $violation) {
 *         echo htmlspecialchars($violation->message), "<br>";
 *     }
 *
 * errors holds one Violation per distinct problem, in document order, at most
 * HtmlValidator::$maxErrors of them. A Violation's detail and message are plain text taken
 * from the content, so HTML-encode them before output.
 */
final class Result
{
    /**
     * true when $errors is empty
     */
    public readonly bool $ok;

    /**
     * @param Violation[] $errors
     */
    public function __construct(public readonly array $errors)
    {
        $this->ok = $errors === [];
    }
}
