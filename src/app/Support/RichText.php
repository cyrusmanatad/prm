<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The formatting a public introduction may use: paragraphs, line breaks, bold, italic, one
 * subheading level, lists and links. Everything else is removed, on save and again on display.
 */
class RichText
{
    public const MAX_TEXT = 5000;

    public const MAX_HTML = 20000;

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        self::$sanitizer ??= new HtmlSanitizer((new HtmlSanitizerConfig)
            ->allowElement('p')->allowElement('br')->allowElement('strong')->allowElement('em')
            ->allowElement('h3')->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_HTML * 2));
        $clean = trim(self::$sanitizer->sanitize($html));

        return self::text($clean) === '' ? '' : $clean;
    }

    /** Stored introductions written before formatting are plain text; they become paragraphs. */
    public static function fromStored(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (! str_starts_with($stored, '<')) {
            $paragraphs = preg_split('/\R{2,}/u', $stored) ?: [];
            $stored = implode('', array_map(
                fn ($paragraph) => '<p>'.preg_replace('/\R/u', '<br>', htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</p>',
                array_filter($paragraphs, fn ($paragraph) => trim($paragraph) !== ''),
            ));
        }

        return self::sanitize($stored);
    }

    /** Visible text, for limits, search descriptions and exports. */
    public static function text(string $html): string
    {
        $spaced = preg_replace('/<(?:br|\/p|\/h3|\/li)\b[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
