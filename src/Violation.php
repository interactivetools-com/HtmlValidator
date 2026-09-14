<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function sprintf;

/**
 * One rule the content broke. Found in Result::$errors.
 *
 *     $violation->code;       // 'element-not-allowed'
 *     $violation->detail;     // '<script src="https://example.com/x.js">'
 *     $violation->template;   // '%s is not allowed'
 *     $violation->message;    // '<script src="https://example.com/x.js"> is not allowed'
 *
 * code is stable across releases, so callers can switch on it. template is the English
 * sentence with one %s where detail goes, and message is the two combined. To translate,
 * run the template through your translation function and sprintf() the detail back in:
 *
 *     echo sprintf(t($violation->template), htmlspecialchars($violation->detail));
 *
 * TEMPLATES lists every template by code, so a translation system can register all of
 * them up front. detail comes from the content: always one line of valid UTF-8, cut to
 * a readable length, but it can hold </script>, quotes and backticks, so encode it for
 * wherever it goes (HTML-encode for a page, json_encode() with the JSON_HEX_* flags for
 * a <script> block).
 */
final class Violation
{
    public const TEMPLATES = [
        'not-utf8'               => 'Content must be UTF-8, this is not: %s',
        'control-character'      => 'Content contains a control character: %s',
        'element-not-allowed'    => '%s is not allowed',
        'event-handler'          => '%s= event handler attributes are not allowed',
        'attribute-not-allowed'  => 'The %s attribute is not allowed',
        'url-scheme-not-allowed' => 'The URL in %s must start with http:, https:, mailto:, tel:, a relative path, or #',
        'iframe-host'            => 'Embedding frames from %s is not allowed',
        'css-not-allowed'        => 'CSS containing %s is not allowed',
        'unclosed-markup'        => '%s is not closed',
    ];

    public readonly string $template;
    public readonly string $message;

    public function __construct(
        public readonly string $code,
        public readonly string $detail,
    ) {
        $this->template = self::TEMPLATES[$code];
        $this->message  = sprintf($this->template, $detail);
    }
}
