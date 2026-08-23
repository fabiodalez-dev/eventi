<?php

declare(strict_types=1);

use Tests\Support\VenueIsolationScenario;

/**
 * Ogni pagina di `/gestione` si apre davvero, e **nessuna stampa una chiave di
 * traduzione al posto di una parola**.
 *
 * È lo stesso guardiano che sorveglia `/admin`: una `__('manage.qualcosa')`
 * scritta male non fa fallire nulla, si limita a stampare la chiave — e qui
 * la vedrebbe un gestore, non un collega.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->actingAs($this->scenario->ownerA);
});

/**
 * @return array<int, string>
 */
function rawVenueTranslationKeys(string $html): array
{
    preg_match_all('/\b(?:manage|admin|enums|common|dates)\.[a-z_]+\.[a-z_.]+\b/', $html, $own);

    /*
     * Anche le chiavi dei pacchetti, che hanno il namespace con i due punti.
     * Filament introduce etichette nuove piu in fretta di quanto vengano
     * tradotte a monte, e quasi tutte sono aria-label per gli screen reader:
     * la pagina resta all'apparenza corretta mentre chi naviga con la tastiera
     * o con un lettore di schermo sente "filament::components/breadcrumbs.label".
     *
     * I namespace sono elencati uno per uno perche Filament usa la stessa
     * sintassi anche per identificatori interni (wire:partial =
     * "schema-component::form.name"), che non sono traduzioni.
     */
    $namespaces = 'filament|filament-panels|filament-forms|filament-tables|filament-actions'
        .'|filament-notifications|filament-infolists|filament-widgets|filament-schemas'
        .'|filament-query-builder';

    preg_match_all('/\b(?:'.$namespaces.')::[a-z0-9\/_-]+\.[a-z_.]+\b/', $html, $vendor);

    return array_values(array_unique([...$own[0], ...$vendor[0]]));
}

it('apre ogni pagina senza mostrare chiavi di traduzione', function (string $path): void {
    $response = $this->get('/gestione/'.$this->scenario->venueA->slug.$path);

    $response->assertOk();

    expect(rawVenueTranslationKeys($response->getContent()))->toBe([], $path);
})->with([
    'riepilogo' => '',
    'eventi' => '/eventi',
    'nuovo evento' => '/eventi/nuovo',
    'statistiche' => '/statistiche',
    'collaboratori' => '/collaboratori',
]);

it('apre la scheda di un evento senza chiavi di traduzione', function (): void {
    $response = $this->get(
        '/gestione/'.$this->scenario->venueA->slug.'/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica',
    );

    $response->assertOk();

    expect(rawVenueTranslationKeys($response->getContent()))->toBe([]);
});

it('mostra le scorciatoie del wizard, che sono metà dei 90 secondi', function (): void {
    $response = $this->get('/gestione/'.$this->scenario->venueA->slug.'/eventi/nuovo');

    $response->assertOk();
    $response->assertSee(__('manage.shortcuts.tonight'));
    $response->assertSee(__('manage.shortcuts.tomorrow'));
    $response->assertSee(__('manage.shortcuts.friday'));
    $response->assertSee(__('manage.shortcuts.every_thursday'));
});

it('non nomina mai la sintassi delle ricorrenze in pagina', function (): void {
    $response = $this->get('/gestione/'.$this->scenario->venueA->slug.'/eventi/nuovo');

    $response->assertOk();
    $response->assertDontSee('RRULE');
    $response->assertDontSee('FREQ=');
    $response->assertDontSee('BYDAY');
});
