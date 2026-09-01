<?php

declare(strict_types=1);

use App\Support\SafeUrl;

/**
 * Cosa può finire in un `href` che arriva dal database.
 *
 * Blade sfugge il contenuto di un attributo — quindi nessuno può chiudere le
 * virgolette e scrivere markup — ma non impedisce che l'indirizzo STESSO sia
 * `javascript:`. Quel valore esegue codice al clic, e nessuna quantità di
 * escaping lo cambia: è un URL che fa ciò che gli URL di quello schema fanno.
 */
it('lascia passare http e https', function (string $url): void {
    expect(SafeUrl::href($url))->toBe($url);
})->with([
    'https://circolo.example',
    'http://circolo.example/pagina?x=1#ancora',
    'HTTPS://MAIUSCOLO.EXAMPLE',
]);

it('rifiuta gli schemi che eseguono qualcosa', function (string $url): void {
    expect(SafeUrl::href($url))->toBeNull();
})->with([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    "java\tscript:alert(1)",
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
]);

it('rifiuta cio che non e un indirizzo assoluto', function (mixed $valore): void {
    /* Qui si tratta sempre di destinazioni esterne: un percorso relativo che
       arriva dai dati non è una cosa da seguire, e una stringa vuota in un
       `href` porta alla pagina stessa — peggio di un collegamento assente. */
    expect(SafeUrl::href($valore))->toBeNull();
})->with([
    '/percorso/relativo',
    'circolo.example',
    '',
    '   ',
    null,
    123,
]);
