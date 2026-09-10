<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeUrlTest extends TestCase
{
    #[DataProvider('safeUrls')]
    public function test_allows_web_links(string $url): void
    {
        self::assertSame($url, SafeUrl::href($url));
    }

    public static function safeUrls(): array
    {
        return [['https://circolo.example'], ['http://circolo.example/pagina?x=1#ancora'], ['HTTPS://MAIUSCOLO.EXAMPLE']];
    }

    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_and_relative_links(mixed $url): void
    {
        self::assertNull(SafeUrl::href($url));
    }

    public static function unsafeUrls(): array
    {
        return [['javascript:alert(1)'], ['JaVaScRiPt:alert(1)'], ["java\tscript:alert(1)"],
            ['data:text/html,<script>alert(1)</script>'], ['vbscript:msgbox(1)'], ['file:///etc/passwd'],
            ['/percorso/relativo'], ['circolo.example'], [''], ['   '], [null], [123], [false], [[]]];
    }

    public function test_trims_surrounding_whitespace_without_changing_the_link(): void
    {
        self::assertSame('https://circolo.example/evento?q=1#programma', SafeUrl::href(" \thttps://circolo.example/evento?q=1#programma\n"));
    }
}
