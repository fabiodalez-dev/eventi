<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Description;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gli a capo scritti dentro una descrizione HTML diventano `<br>`; quelli fra
 * due blocchi restano formattazione. Il testo escapato resta escapato.
 */
final class DescriptionBreaksTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_turns_written_line_breaks_into_br(string $html, string $expected): void
    {
        self::assertSame($expected, (string) Description::render($html));
    }

    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'a capo nel paragrafo' => ["<p>a\nb</p>", '<p>a<br>b</p>'],
            'fra due paragrafi' => ["<p>a</p>\n<p>b</p>", "<p>a</p>\n<p>b</p>"],
            'elenco' => ["<ul>\n<li>a</li>\n<li>b</li>\n</ul>", "<ul>\n<li>a</li>\n<li>b</li>\n</ul>"],
            'dopo un grassetto' => ["<p>a <strong>b</strong>\nc</p>", '<p>a <strong>b</strong><br>c</p>'],
            'in testa e in coda' => ["<p>\nriga<br>già spezzata\n</p>", '<p>riga<br>già spezzata</p>'],
            'emoji e accenti' => ["<p>Venerdì città!\n👉 Porte alle 20.45</p>", '<p>Venerdì città!<br>👉 Porte alle 20.45</p>'],
            'testo escapato' => ["<p>&lt;script&gt;alert(1)&lt;/script&gt;\nx &amp; y</p>", '<p>&lt;script&gt;alert(1)&lt;/script&gt;<br>x &amp; y</p>'],
        ];
    }

    public function test_never_lets_markup_through_the_breaks(): void
    {
        $html = (string) Description::render("<p>uno\n<script>alert(1)</script>\n<img src=x onerror=alert(2)>due</p>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringContainsString('<br>', $html);
    }

    public function test_plain_text_still_uses_nl2br_with_escaping(): void
    {
        self::assertSame('a &amp; &quot;b&quot;<br />'."\n".'c', (string) Description::render("a & \"b\"\nc"));
    }

    public function test_plain_text_becomes_editor_paragraphs(): void
    {
        self::assertSame(
            '<p>Riga uno<br>Ingresso &lt;10 anni &amp; ridotti</p><p>Secondo paragrafo<br>fine</p>',
            Description::fromPlainText("Riga uno\nIngresso <10 anni & ridotti\n\n\nSecondo paragrafo  \r\n  fine\n"),
        );
        self::assertSame('', Description::fromPlainText("  \n "));
        self::assertSame('<p>Testo sicuro</p>', Description::fromPlainText('<p>Testo sicuro</p><script>alert(1)</script><img src=x onerror=alert(2)>'));
    }

    public function test_plain_text_view_of_the_new_html_keeps_the_lines(): void
    {
        self::assertSame("Riga uno\nRiga due\nSecondo", Description::plain(Description::fromPlainText("Riga uno\nRiga due\n\nSecondo")));
    }
}
