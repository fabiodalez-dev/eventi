<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\Csp;

/**
 * Le intestazioni di sicurezza di §16.
 *
 * Il motivo per cui esistono questi test: in produzione `nosniff`,
 * `X-Frame-Options` e `X-XSS-Protection` arrivavano dal pannello dell'hosting
 * e non da nessuna riga del repository. Funzionavano, e sarebbero sparite in
 * silenzio alla prima migrazione senza che nulla se ne accorgesse.
 */
beforeEach(function (): void {
    $this->city = testCity();
});

it('accompagna ogni pagina pubblica con le intestazioni di base', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=(self)')
        ->and($response->headers->get('Content-Security-Policy'))
        ->toContain("worker-src 'self' blob:")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'self'");
});

/*
 * Il riquadro incorporabile esiste **per** stare nella pagina di qualcun
 * altro: dichiara da sé `frame-ancestors *`, e nessuna intestazione aggiunta
 * dopo deve restringerlo. Due politiche CSP non si scelgono, si sommano nella
 * loro intersezione — quindi qui non basta arrivare primi, bisogna proprio non
 * scrivere.
 */
it('lascia incorniciabile il riquadro da incorporare', function (): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'approved']);

    $this->get('/widget/'.$venue->slug)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
        ->assertHeaderMissing('X-Frame-Options');
});

it('lega gli script al nonce sul sito pubblico e non nei pannelli', function (): void {
    $pubblica = $this->get('/');
    $politica = (string) $pubblica->headers->get('Content-Security-Policy');

    expect($politica)->toContain("'strict-dynamic'")->toContain("script-src 'self' 'nonce-");

    /* Il numero dichiarato nell'intestazione è quello che sta sui tag. */
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $politica, $trovato);
    expect($trovato[1] ?? '')->not->toBe('')
        ->and($pubblica->getContent())->toContain('nonce="'.$trovato[1].'"');

    /* I pannelli sono Filament: markup suo, script suoi, nessun contenuto di
       sconosciuti da stampare. Restano fuori. */
    expect((string) $this->get('/admin/login')->headers->get('Content-Security-Policy'))
        ->not->toContain('script-src');
});

/**
 * Il caso che rompe tutto se sfugge: una pagina **servita dalla cache** porta
 * nel corpo il nonce di chi l'ha generata. Se non venisse sostituito, ogni
 * visitatore successivo riceverebbe un documento i cui script dichiarano un
 * numero diverso da quello della propria intestazione — cioè una pagina senza
 * JavaScript, per tutti, fino alla scadenza della copia.
 */
it('rimette un nonce nuovo nella pagina servita dalla cache', function (): void {
    config()->set('page_cache.enabled', true);
    freezeLocal($this->city, '2026-09-12 18:00');
    occurrenceAtLocal($this->city, testCategory(), '2026-09-12 21:30');

    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');

    $primo = $this->get('/eventi');
    $primo->assertHeader('X-Page-Cache', 'hit');

    /* La seconda risposta arriva dalla stessa copia salvata, ma con il proprio
       numero: il contenitore va svuotato, altrimenti il test riuserebbe il
       `Csp` della richiesta precedente e non proverebbe niente. */
    app()->forgetScopedInstances();
    $secondo = $this->get('/eventi');
    $secondo->assertHeader('X-Page-Cache', 'hit');

    $nonceDi = function ($response): string {
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $trovato);

        return $trovato[1] ?? '';
    };

    $uno = $nonceDi($primo);
    $due = $nonceDi($secondo);

    expect($uno)->not->toBe('')
        ->and($due)->not->toBe('')
        ->and($due)->not->toBe($uno)
        ->and($primo->getContent())->toContain('nonce="'.$uno.'"')->not->toContain('@@csp-nonce@@')
        ->and($secondo->getContent())->toContain('nonce="'.$due.'"')->not->toContain('@@csp-nonce@@');
});

it('non emette HSTS su una richiesta in chiaro', function (): void {
    $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
});

it('non genera alcun nonce dove la politica non si applica', function (): void {
    expect(app(Csp::class)->issued())->toBeNull()
        ->and(app(Csp::class)->attribute())->toBe('');
});
