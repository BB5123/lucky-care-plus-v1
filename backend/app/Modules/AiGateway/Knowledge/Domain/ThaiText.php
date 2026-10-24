<?php

namespace App\Modules\AiGateway\Knowledge\Domain;

/**
 * Normalises text for lexical matching in the health knowledge base.
 *
 * Two problems make Thai retrieval miss documents that a human would find:
 *
 *  1. zero-width characters and stray whitespace inside a Thai word
 *     ("มือ\u{200B} เท้า ปาก") are invisible in a rendered page but are part of
 *     the FTS5 token stream, so the trigram index never sees "มือเท้าปาก";
 *  2. source pages write "มือ เท้า ปาก" while users type "มือเท้าปาก".
 *
 * Collapsing whitespace *between Thai characters* makes the two the same token
 * stream, while whitespace between Latin words is preserved ("Hand, Foot and
 * Mouth" must keep its words). A space between a Latin letter and a digit is
 * also removed so "PM 2.5" and "PM2.5" match: that is a measurement spelling
 * variant, not a word boundary.
 *
 * The normaliser is applied to both the indexed text and the query, so the
 * comparison is symmetric.
 */
final class ThaiText
{
    /**
     * Invisible characters that browsers hide but FTS5 indexes: zero-width
     * space, zero-width non-joiner/joiner, word joiner, BOM/ZWNBSP and the
     * soft hyphen.
     */
    private const ZERO_WIDTH = [
        "\u{200B}",
        "\u{200C}",
        "\u{200D}",
        "\u{2060}",
        "\u{FEFF}",
        "\u{00AD}",
    ];

    /**
     * Whitespace (including non-breaking and typographic spaces) between two
     * Thai characters carries no meaning for matching.
     */
    private const THAI_GAP = '/(?<=\p{Thai})[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+(?=\p{Thai})/u';

    public static function normalise(string $text): string
    {
        $text = str_replace(self::ZERO_WIDTH, '', $text);
        $text = preg_replace(self::THAI_GAP, '', $text) ?? $text;

        // "PM 2.5" / "PM2.5" are the same measurement. Only the letter→digit
        // gap is removed; spaces between words stay untouched.
        $text = preg_replace('/(?<=[A-Za-z])[ \t]+(?=\d)/u', '', $text) ?? $text;

        return trim($text);
    }
}
