<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Description
{
    /**
     * Gli elementi che contengono testo in riga: un a capo scritto dentro di
     * loro è un a capo voluto da chi ha scritto, non formattazione del codice.
     */
    private const INLINE_CONTAINERS = ['p', 'li', 'h2', 'h3', 'a', 'strong', 'b', 'em', 'i', 'u', 's'];

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

        /*
         * La versione testo la leggono l'app e chi non mostra HTML: deve
         * tenere la forma che si vede sul sito. Fra due paragrafi resta una
         * riga vuota, ogni voce di elenco va a capo col suo punto o numero.
         */
        $html = self::sanitize($text);
        // L'editor chiude ogni voce di elenco in un paragrafo: lì non è uno stacco.
        $html = preg_replace('~</p>(\s*</li>)~i', '$1', $html) ?? $html;
        $html = preg_replace_callback('~(<ol\b[^>]*>)(.*?)(</ol>)~is', function (array $list): string {
            $number = 0;

            return $list[1].(preg_replace_callback('~<li\b[^>]*>~i', function () use (&$number): string {
                $number++;

                return "\n".$number.'. ';
            }, $list[2]) ?? $list[2]).$list[3];
        }, $html) ?? $html;
        $html = preg_replace('~<li\b[^>]*>~i', "\n• ", $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(?:p|h2|h3|blockquote|ul|ol)>~i', "\n\n", $html) ?? $html;

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/', "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    public static function render(?string $text): HtmlString
    {
        $text ??= '';

        return new HtmlString($text === strip_tags($text) ? nl2br(e($text)) : self::breakLines(self::sanitize($text)));
    }

    /**
     * Un testo semplice (l'import da Facebook, un incolla da WhatsApp) diventa
     * HTML dell'editor senza perdere la forma: una riga vuota apre un
     * paragrafo nuovo, un a capo singolo diventa `<br>`. Il testo è sempre
     * escapato; se arriva già con i tag passa dalla stessa sanificazione di
     * ogni altra descrizione.
     */
    public static function fromPlainText(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text ?? ''));
        if ($text === '') {
            return '';
        }
        // Solo i tag veri contano come HTML: «Ingresso <10 anni» resta testo.
        if (preg_match('~</?(?:p|br|div|span|strong|b|em|i|u|s|h[1-6]|ul|ol|li|blockquote|a|img|script|style|iframe)\b[^>]*>~i', $text) === 1) {
            return self::breakLines(self::sanitize($text));
        }

        $paragraphs = preg_split('/\n[ \t]*\n\s*/u', $text) ?: [$text];

        return collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>'.collect(preg_split('/[ \t]*\n[ \t]*/u', trim($paragraph)) ?: [])
                ->map(fn (string $line): string => e($line))
                ->join('<br>').'</p>')
            ->join('');
    }

    /**
     * Gli a capo scritti dentro il testo diventano `<br>`.
     *
     * Le descrizioni importate o incollate arrivano spesso come
     * `<p>riga uno\nriga due</p>`: l'HTML tratta quell'a capo come uno
     * spazio, e il testo si legge tutto attaccato. Qui si lavora solo su HTML
     * già sanificato e si creano solo nodi di testo e `<br>`: niente di quello
     * che entra può diventare un tag. Gli a capo fra due blocchi
     * (`</p>\n<p>`) sono formattazione e restano come sono; quelli in testa o
     * in coda a un elemento non aggiungono righe vuote.
     */
    public static function breakLines(string $html): string
    {
        if (! str_contains($html, "\n")) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="description-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new DOMXPath($dom))->query('//text()[contains(., "'."\n".'")]');
        $changed = false;

        foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
            if (! $node instanceof DOMText || ! $node->parentNode instanceof DOMNode) {
                continue;
            }
            $parent = $node->parentNode;
            $atRoot = $parent instanceof \DOMElement && $parent->getAttribute('id') === 'description-root';
            // Fra due blocchi (o in un elenco) l'a capo è solo formattazione; in
            // radice conta solo il testo vero, scritto senza paragrafo intorno.
            if (! ($atRoot ? trim($node->nodeValue ?? '') !== '' : in_array(strtolower($parent->nodeName), self::INLINE_CONTAINERS, true))) {
                continue;
            }

            $parts = preg_split('/[ \t]*\R[ \t]*/u', $node->nodeValue ?? '') ?: [];
            if ($node->previousSibling === null) {
                while ($parts !== [] && trim($parts[0]) === '') {
                    array_shift($parts);
                }
            }
            if ($node->nextSibling === null) {
                while ($parts !== [] && trim($parts[array_key_last($parts)]) === '') {
                    array_pop($parts);
                }
            }

            $fragment = $dom->createDocumentFragment();
            foreach ($parts as $index => $part) {
                if ($index > 0) {
                    $fragment->appendChild($dom->createElement('br'));
                }
                if ($part !== '') {
                    $fragment->appendChild($dom->createTextNode($part));
                }
            }

            if ($fragment->hasChildNodes()) {
                $node->parentNode->replaceChild($fragment, $node);
            } else {
                $node->parentNode->removeChild($node);
            }
            $changed = true;
        }

        if (! $changed) {
            return $html;
        }

        $root = $dom->getElementById('description-root');
        $out = '';
        foreach ($root === null ? [] : $root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
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
