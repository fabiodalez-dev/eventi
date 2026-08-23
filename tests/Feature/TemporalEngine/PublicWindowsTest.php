<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;

/*
 * Le finestre e i conteggi aggiunti dal sito pubblico (§11): stanno nel motore
 * temporale e non nei controller, perché "da oggi in poi" resta una definizione
 * di oggi (§8.1) e chi la ricalcola altrove crea la seconda verità.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('separa il futuro dal passato sulla giornata evento, non sull\'istante', function (): void {
    $city = testCity();
    $category = testCategory();

    /* Sono le 2 di notte del 6: la serata del 5 è ancora "oggi" solo se la
       categoria è notturna; qui non lo è, quindi il 5 è passato. */
    freezeLocal($city, '2026-09-06 14:00:00');

    $ieri = occurrenceAtLocal($city, $category, '2026-09-05 21:00:00');
    $oggi = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    $domani = occurrenceAtLocal($city, $category, '2026-09-07 21:00:00');

    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->get()))
        ->toBe([(int) $oggi->getKey(), (int) $domani->getKey()]);

    expect(idsOf(EventOccurrenceQuery::for($city)->past()->get()))
        ->toBe([(int) $ieri->getKey()]);
});

it('ordina l\'archivio dalla data più recente, che è come si legge un archivio', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-06 12:00:00');

    $vecchia = occurrenceAtLocal($city, $category, '2026-08-01 21:00:00');
    $recente = occurrenceAtLocal($city, $category, '2026-09-04 21:00:00');

    expect(idsOf(EventOccurrenceQuery::for($city)->past()->orderByNewestFirst()->get()))
        ->toBe([(int) $recente->getKey(), (int) $vecchia->getKey()]);
});

it('conta i prossimi giorni con una sola interrogazione aggregata', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00');
    occurrenceAtLocal($city, $category, '2026-09-05 22:00:00');
    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00');
    /* Fuori finestra: quattordici giorni contano da oggi compreso. */
    occurrenceAtLocal($city, $category, '2026-09-30 21:00:00');

    expect(EventOccurrenceQuery::for($city)->nextDays(14)->countsByBusinessDate())
        ->toBe(['2026-09-05' => 2, '2026-09-07' => 1]);
});

it('conta le occorrenze per categoria e per locale', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 12:00:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', venue: $venue);
    occurrenceAtLocal($city, $musica, '2026-09-07 21:00:00', venue: $venue);
    occurrenceAtLocal($city, $teatro, '2026-09-08 21:00:00');

    expect(EventOccurrenceQuery::for($city)->upcoming()->countsByCategory())
        ->toBe([(int) $musica->getKey() => 2, (int) $teatro->getKey() => 1]);

    expect(EventOccurrenceQuery::for($city)->upcoming()->countsByVenue()[$venue->getKey()])->toBe(2);
});

it('seleziona solo l\'evidenza ancora valida', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $viva = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: [
        'is_featured' => true,
        'featured_until' => Carbon::parse('2026-09-30 00:00:00', 'UTC'),
    ]);

    $senzaScadenza = occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', event: [
        'is_featured' => true,
        'featured_until' => null,
    ]);

    occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', event: [
        'is_featured' => true,
        'featured_until' => Carbon::parse('2026-08-01 00:00:00', 'UTC'),
    ]);

    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['is_featured' => false]);

    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->featured()->get()))
        ->toBe([(int) $viva->getKey(), (int) $senzaScadenza->getKey()]);
});

it('filtra per comune, per spazio aperto e per accessibilità del locale', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $este = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'municipality' => 'Este',
        'accessibility' => ['wheelchair' => true],
    ]);

    $padova = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'municipality' => 'Padova',
        'accessibility' => ['wheelchair' => false],
    ]);

    $aperto = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $este, event: ['is_outdoor' => true]);
    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', venue: $padova, event: ['is_outdoor' => false]);

    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->inMunicipality('Este')->get()))->toBe([(int) $aperto->getKey()]);
    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->outdoor()->get()))->toBe([(int) $aperto->getKey()]);
    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->accessible()->get()))->toBe([(int) $aperto->getKey()]);
    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->atVenueSlug($este->slug)->get()))->toBe([(int) $aperto->getKey()]);
});

it('tiene fuori un evento dai suoi simili e tiene dentro le sue date', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $primo = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    $secondo = occurrenceAtLocal($city, $category, '2026-09-07 21:00:00');

    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->forEvent($primo->event)->get()))
        ->toBe([(int) $primo->getKey()]);

    expect(idsOf(EventOccurrenceQuery::for($city)->upcoming()->excludingEvent($primo->event)->get()))
        ->toBe([(int) $secondo->getKey()]);
});
