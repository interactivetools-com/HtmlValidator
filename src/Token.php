<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

/**
 * One token from Tokenizer::tokens(): a start tag, end tag, text run, comment, doctype, or
 * the UNCLOSED marker.
 *
 *     foreach ((new Tokenizer($html))->tokens() as $token) {
 *         if ($token->type === Token::START_TAG && $token->name === 'script') {
 *             echo "script tag at byte $token->start";
 *         }
 *     }
 *
 * Names are ASCII-lowercased and never entity-decoded, as in browsers. Attribute values and
 * text are entity-decoded; comment and doctype data are not. start and end are byte offsets
 * into the input after CR and CRLF were normalized to LF, so substr($html, $start, $end - $start)
 * is the token's source when the input had LF line endings.
 *
 * UNCLOSED is always the last token, and only comes when the input ends inside a tag, a
 * comment, a doctype, or the content of a raw-text element such as <style>. At the end of a
 * document a browser drops the unfinished tag, but a fragment printed into a page is not at
 * the end: the page up to the next quote becomes the attribute value, or the page up to
 * </style> becomes CSS. start is where the unfinished markup opened and end is the end of
 * the input, so the source is the whole unfinished piece.
 *
 * @internal The validator's own parser, not part of the public API; it can change between releases.
 */
final class Token
{
    public const START_TAG = 1;
    public const END_TAG   = 2;
    public const TEXT      = 3;
    public const COMMENT   = 4;
    public const DOCTYPE   = 5;
    public const UNCLOSED  = 6;

    /**
     * @param int                   $type        one of the constants above
     * @param string                $name        tag name for START_TAG and END_TAG, the tag or raw-text element the input ended inside for UNCLOSED, '' otherwise
     * @param array<string|int, string> $attributes  name => decoded value; the first of duplicate names wins (START_TAG only). A name of digits only is an int key, as PHP stores it
     * @param bool                  $selfClosing the tag ended with /> (START_TAG only)
     * @param string                $data        decoded text (TEXT), comment body (COMMENT), everything after <!DOCTYPE (DOCTYPE)
     * @param int                   $start       byte offset of the token's first character
     * @param int                   $end         byte offset just past the token
     */
    public function __construct(
        public readonly int    $type,
        public readonly string $name,
        public readonly array  $attributes,
        public readonly bool   $selfClosing,
        public readonly string $data,
        public readonly int    $start,
        public readonly int    $end,
    ) {
    }
}
