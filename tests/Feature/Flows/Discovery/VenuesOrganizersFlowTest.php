<?php

declare(strict_types=1);

use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\Venue;
use Carbon\Carbon;

require_once __DIR__.'/Helpers.php';

/*
 * Scoprire partendo dal luogo o da chi organizza: l'elenco dei locali con la
 * scheda di ciascuno, l'elenco degli organizzatori con la loro programmazione
 * e il loro archivio.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('cerca un locale per comune e nome e ne apre la programmazione', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $osteria = scopertaLocale($city, ['name' => 'Osteria dei Musici', 'municipality' => 'Este']);
    $club = scopertaLocale($city, ['name' => 'Club Selvatico', 'municipality' => 'Padova']);
    $bozza = Venue::factory()->create(['city_id' => $city->id, 'name' => 'Locale ancora in bozza', 'status' => VenueStatus::Draft]);
    $attesa = Venue::factory()->create(['city_id' => $city->id, 'name' => 'Locale in attesa di verifica', 'status' => VenueStatus::Pending]);

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Musica popolare in osteria'], venue: $osteria);
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Ballo liscio in osteria'], venue: $osteria);
    occurrenceAtLocal($city, $category, '2026-09-02 21:00:00', event: ['title' => 'Serata passata in osteria'], venue: $osteria);
    occurrenceAtLocal($city, $category, '2026-09-11 23:00:00', event: ['title' => 'Notte elettronica al club'], venue: $club);

    /* L'elenco mostra solo i locali approvati, con le date in programma. */
    $this->get('/locali')
        ->assertOk()
        ->assertSee('Osteria dei Musici')
        ->assertSee('Club Selvatico')
        ->assertDontSee('Locale ancora in bozza')
        ->assertDontSee('Locale in attesa di verifica')
        ->assertSee(trans_choice('venues.card.upcoming', 2, ['count' => 2]));

    /* Filtri: comune e testo. */
    $this->get('/locali?municipality=Este')->assertOk()->assertSee('Osteria dei Musici')->assertDontSee('Club Selvatico');
    $this->get('/locali?q=Selvatico')
        ->assertOk()
        ->assertSee('Club Selvatico')
        ->assertDontSee('Osteria dei Musici')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

    /* La scheda: solo le serate future di quel locale. */
    $this->get('/locali/'.$osteria->slug)
        ->assertOk()
        ->assertSee('Osteria dei Musici')
        ->assertSee('Musica popolare in osteria')
        ->assertSee('Ballo liscio in osteria')
        ->assertDontSee('Notte elettronica al club');

    /* Da lì, la lista degli eventi ristretta al locale. */
    $this->get('/eventi?venue='.$osteria->slug)
        ->assertOk()
        ->assertSee('Musica popolare in osteria')
        ->assertDontSee('Notte elettronica al club')
        ->assertDontSee('Serata passata in osteria');

    $this->get('/locali/'.$bozza->slug)->assertNotFound();
    $this->get('/locali/'.$attesa->slug)->assertNotFound();
    $this->get('/locali/locale-mai-esistito')->assertNotFound();
});

it('sfoglia gli organizzatori, ne apre la programmazione e il suo archivio', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $collettivo = Organizer::create(['name' => 'Collettivo Portello Sonoro', 'city_id' => $city->id, 'is_active' => true]);
    $associazione = Organizer::create(['name' => 'Associazione Lettori Lenti', 'city_id' => $city->id, 'is_active' => true]);
    $spento = Organizer::create(['name' => 'Organizzatore disattivato', 'city_id' => $city->id, 'is_active' => false]);

    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Rave acustico sul canale', 'organizer_id' => $collettivo->id]);
    occurrenceAtLocal($city, $category, '2026-08-20 21:00:00', event: ['title' => 'Prima edizione del rave acustico', 'organizer_id' => $collettivo->id]);
    occurrenceAtLocal($city, $category, '2026-09-13 18:00:00', event: ['title' => 'Lettura ad alta voce', 'organizer_id' => $associazione->id]);

    $this->get('/organizzatori')
        ->assertOk()
        ->assertSee('Collettivo Portello Sonoro')
        ->assertSee('Associazione Lettori Lenti')
        ->assertDontSee('Organizzatore disattivato');

    $this->get('/organizzatori?q=Lettori')
        ->assertOk()
        ->assertSee('Associazione Lettori Lenti')
        ->assertDontSee('Collettivo Portello Sonoro')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

    /* La scheda mostra il programma in arrivo, non l'archivio né gli altri. */
    $scheda = $this->get('/organizzatori/'.$collettivo->slug)
        ->assertOk()
        ->assertSee('Rave acustico sul canale')
        ->assertDontSee('Prima edizione del rave acustico')
        ->assertDontSee('Lettura ad alta voce');

    expect(scopertaCanonico($scheda))->toBe(route('organizers.show', $collettivo));

    /* L'archivio è la stessa scheda guardata all'indietro, con lo stesso canonico. */
    $archivio = $this->get('/organizzatori/'.$collettivo->slug.'?past=1')
        ->assertOk()
        ->assertSee('Prima edizione del rave acustico')
        ->assertDontSee('Rave acustico sul canale');

    expect(scopertaCanonico($archivio))->toBe(route('organizers.show', $collettivo));

    $this->get('/organizzatori/'.$spento->slug)->assertNotFound();
});
