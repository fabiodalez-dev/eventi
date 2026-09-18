<?php

declare(strict_types=1);

use App\Support\Seo\PageTitle;
use Tests\TestCase;

/*
 * Serve l'applicazione — la classe legge il nome del sito dalla
 * configurazione e lo schema del titolo dal file di lingua — ma non il
 * database: `RefreshDatabase` qui sarebbe una migrazione a ogni caso per
 * confrontare stringhe.
 */
uses(TestCase::class);

/**
 * Il titolo della scheda evento sta nella misura che un motore mostra.
 *
 * I titoli usati qui sono presi dagli eventi veri pubblicati sul sito: prima
 * di questa classe sette schede su otto superavano i sessanta caratteri, e la
 * più lunga arrivava a novantanove.
 */
function intero(string $titolo): int
{
    return mb_strlen($titolo.PageTitle::SITE_SEPARATOR.config()->string('app.name'));
}

it('tiene il titolo intero quando ci sta', function (): void {
    $titolo = PageTitle::forEvent('TEATRO BRESCI | Malabrenta', 'Padova', '13/09/2026');

    expect($titolo)->toBe('TEATRO BRESCI | Malabrenta a Padova, 13/09/2026')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('non ripete la città che il titolo nomina già', function (): void {
    /* Prima diceva «PARCO DELLA MUSICA - PADOVA a Padova». */
    $titolo = PageTitle::forEvent('U2 VELVET DRESS - OKTOBERFEST 2026 - PARCO DELLA MUSICA - PADOVA', 'Padova', '18/09/2026');

    expect($titolo)->not->toContain(' a Padova')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('riconosce la città anche scritta con accenti o in maiuscolo', function (string $evento): void {
    expect(PageTitle::forEvent($evento, 'Forlì'))->not->toContain(' a Forlì');
})->with(['Notte bianca a FORLI', 'Notte bianca a forlì']);

it('accorcia il titolo e non la data', function (): void {
    $titolo = PageTitle::forEvent('Padova Suona Walt Disney | Gran Concerto al Piccolo Teatro Don Bosco', 'Padova', '11/12/2026');

    expect($titolo)->toEndWith(', 11/12/2026')
        ->and($titolo)->toContain('…')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('taglia su una parola intera e non lascia punteggiatura appesa', function (): void {
    $titolo = PageTitle::forEvent('Anime in Plexiglass | ACUSTICO | Amsterdam - Padova', 'Padova', '10/10/2026');

    expect($titolo)->not->toMatch('/[-–—|,;:·.\/\\\\]…/')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('lascia cadere la città prima di accorciare il titolo dell’evento', function (): void {
    /*
     * «Bollicine tributo Vasco… a Padova» perdeva proprio il nome che uno
     * cerca. La città sta comunque nell'indirizzo, nel titolo visibile e nei
     * dati strutturati; il nome dell'artista no.
     */
    $titolo = PageTitle::forEvent('Bollicine tributo Vasco Rossi live in piazza Selvazzano (PD)', 'Padova', '03/10/2026');

    expect($titolo)->toContain('Vasco Rossi')
        ->and($titolo)->not->toContain(' a Padova')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('tiene la città quando toglierla non serve', function (): void {
    expect(PageTitle::forEvent('TEATRO BRESCI | Malabrenta', 'Padova', '13/09/2026'))->toContain(' a Padova');
});

it('rinuncia alla data solo quando è il nome del sito a mangiare la misura', function (): void {
    config()->set('app.name', 'Il portale degli eventi della città');

    $titolo = PageTitle::forEvent('Rassegna internazionale di teatro contemporaneo', 'Padova', '11/12/2026');

    expect($titolo)->not->toContain('11/12/2026')
        ->and(intero($titolo))->toBeLessThanOrEqual(PageTitle::LIMIT);
});

it('sta nella misura su ogni titolo pubblicato davvero', function (string $evento): void {
    expect(intero(PageTitle::forEvent($evento, 'Padova', '01/01/2027')))->toBeLessThanOrEqual(PageTitle::LIMIT);
})->with([
    'Padova Suona Walt Disney | Gran Concerto al Piccolo Teatro Don Bosco',
    'U2 VELVET DRESS - OKTOBERFEST 2026 - PARCO DELLA MUSICA - PADOVA',
    'Bollicine tributo Vasco Rossi live in piazza Selvazzano (PD)',
    'Little Magic the Police tribute band night & Pintafonica',
    'Anime in Plexiglass | ACUSTICO | Amsterdam - Padova',
    '16.10 / SICK TAMBURO @ CSO Pedro - Padova',
    'Day Bau Day - 19-20 settembre 2026',
    'TEATRO BRESCI | Malabrenta',
    'Patti di collaborazione: appuntamenti d’autunno',
    'Jazz',
]);

it('non lascia mai il titolo vuoto, nemmeno con una parola lunghissima', function (): void {
    $titolo = PageTitle::forEvent(str_repeat('a', 200), 'Padova', '01/01/2027');

    expect($titolo)->not->toBe('…')
        ->and(mb_strlen($titolo))->toBeGreaterThan(10);
});
