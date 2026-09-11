<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Description
{
    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public static function plainValues(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value) ? self::plainValues($value) : (is_string($value) ? self::plain($value) : $value);
        }

        return $values;
    }

    public static function plain(?string $text): ?string
    {
        if ($text === null || $text === strip_tags($text)) {
            return $text;
        }

        $html = self::sanitize($text);
        $html = preg_replace('~<br\s*/?>|</(?:p|h2|h3|li|blockquote)>~i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function render(?string $text): HtmlString
    {
        $text ??= '';

        return new HtmlString($text === strip_tags($text) ? nl2br(e($text)) : self::sanitize($text));
    }

    public static function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(100000)->allowLinkSchemes(['http', 'https', 'mailto'])->allowRelativeLinks();
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title']);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'img', 'video', 'audio'] as $tag) {
            $config = $config->dropElement($tag);
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
