<?php

declare(strict_types=1);

use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Gli slug degli eventi nell'ordine in cui compaiono in una pagina del sito.
 *
 * @return list<string>
 */
function slugsInPagina(string $html): array
{
    preg_match_all('#/eventi/([a-z0-9-]+)(?:/date/[0-9]+)?"#', $html, $matches);

    $slugs = [];

    foreach ($matches[1] as $slug) {
        if (! in_array($slug, $slugs, true) && ! in_array($slug, ['oggi', 'domani', 'weekend', 'gratis', 'categoria', 'tag'], true)) {
            $slugs[] = $slug;
        }
    }

    return $slugs;
}

/*
 * §18, scenario G: «Nello stesso istante, `/eventi?preset=starting_soon` e
 * `GET /v1/events?preset=starting_soon` restituiscono la stessa lista nello
 * stesso ordine».
 *
 * Il sito porta la finestra nel parametro `date` e l'API in `preset` (§11.3 e
 * §13.2), ma la finestra è la stessa: entrambi passano da `DatePreset`, che
 * si limita a dire quale metodo di `EventOccurrenceQuery` chiamare.
 */
it('risponde con la stessa lista di "inizia tra poco" al sito e all\'API', function (): void {
    $city = testCity(['starting_soon_minutes' => 180]);
    $category = testCategory();

    freezeLocal($city, '2026-09-05 19:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 19:30:00', event: ['title' => 'Fra mezz\'ora']);
    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Fra due ore']);
    occurrenceAtLocal($city, $category, '2026-09-05 22:30:00', event: ['title' => 'Troppo tardi']);
    occurrenceAtLocal($city, $category, '2026-09-06 19:30:00', event: ['title' => 'Domani']);

    $sito = slugsInPagina($this->get('/eventi?date=starting_soon')->assertOk()->getContent());

    $api = array_column(
        $this->getJson('/api/v1/events?preset=starting_soon')->assertOk()->json('data'),
        'event_slug',
    );

    $motore = EventOccurrenceQuery::for($city)->startingSoon()->get()
        ->map(static fn ($occurrence): string => (string) $occurrence->event->slug)
        ->all();

    expect($api)->toBe($motore)
        ->and($sito)->toBe($motore)
        ->and($api)->toHaveCount(2);
});

it('risponde con la stessa lista di "in corso" al sito e all\'API', function (): void {
    $city = testCity();
    $category = testCategory(['default_duration_minutes' => 180]);
    $mostra = testCategory(['name' => 'Arte e mostre', 'supports_ongoing' => false, 'default_duration_minutes' => 600]);

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Concerto in corso']);
    occurrenceAtLocal($city, $category, '2026-09-05 20:30:00', event: ['title' => 'Iniziato prima']);
    occurrenceAtLocal($city, $mostra, '2026-09-05 10:00:00', '2026-09-05 23:00:00', event: ['title' => 'Mostra']);

    $sito = slugsInPagina($this->get('/eventi?date=ongoing')->assertOk()->getContent());

    $api = array_column(
        $this->getJson('/api/v1/events?preset=ongoing')->assertOk()->json('data'),
        'event_slug',
    );

    $motore = EventOccurrenceQuery::for($city)->ongoing()->get()
        ->map(static fn ($occurrence): string => (string) $occurrence->event->slug)
        ->all();

    expect($api)->toBe($motore)
        ->and($sito)->toBe($motore)
        ->and($api)->toHaveCount(2);
});

it('risponde con la stessa lista di "stasera" al sito e all\'API, filtri compresi', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-05 21:30:00', event: ['title' => 'Concerto di stasera']);
    occurrenceAtLocal($city, $teatro, '2026-09-05 21:00:00', event: ['title' => 'Teatro di stasera']);
    occurrenceAtLocal($city, $musica, '2026-09-05 11:00:00', event: ['title' => 'Concerto di stamattina']);

    $sito = slugsInPagina($this->get('/eventi?date=tonight&category=musica-dal-vivo')->assertOk()->getContent());

    $api = array_column(
        $this->getJson('/api/v1/events?preset=tonight&categories[]=musica-dal-vivo')->assertOk()->json('data'),
        'event_slug',
    );

    expect($api)->toBe($sito)->toHaveCount(1);
});
