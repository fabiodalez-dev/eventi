<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Support\WidgetEmbed;
use Carbon\Carbon;
use Sabre\VObject\Node;
use Sabre\VObject\Reader;

/**
 * Feed e widget (§11.10): «leva di crescita, non optional».
 *
 * Il file `.ics` non si verifica a occhio: lo si dà in pasto a `sabre/vobject`,
 * che è la libreria che parla iCalendar sul serio. Un file che *sembra* giusto
 * e che il telefono rifiuta è peggio di nessun file.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

describe('/eventi.ics', function (): void {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));
    it('produce un calendario valido secondo sabre/vobject', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', '2026-09-06 23:30:00', event: ['title' => 'Concerto di prova']);
        occurrenceAtLocal($city, $category, '2026-09-07 18:00:00', event: ['title' => 'Presentazione del libro']);

        $risposta = $this->get('/eventi.ics')->assertOk();

        $risposta->assertHeader('content-type', 'text/calendar; charset=utf-8');

        $calendario = Reader::read($risposta->getContent());

        expect($calendario->validate())->toBe([])
            ->and($calendario->VEVENT)->toHaveCount(2)
            ->and((string) $calendario->VEVENT[0]->SUMMARY)->toBe('Concerto di prova');
    });

    it('si sottoscrive: dichiara ogni quanto va riletto', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        $calendario = Reader::read($this->get('/eventi.ics')->assertOk()->getContent());

        expect((string) $calendario->{'REFRESH-INTERVAL'})->not->toBe('');
    });

    it('si filtra per categoria, tag e locale come la lista', function (): void {
        $city = testCity();
        $musica = testCategory(['name' => 'Musica dal vivo']);
        $teatro = testCategory(['name' => 'Teatro e danza']);

        freezeLocal($city, '2026-09-05 12:00:00');

        occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', event: ['title' => 'Concerto in programma']);
        occurrenceAtLocal($city, $teatro, '2026-09-06 21:00:00', event: ['title' => 'Spettacolo in programma']);

        $risposta = $this->get('/eventi.ics?category='.$musica->slug)->assertOk();

        $calendario = Reader::read($risposta->getContent());

        expect($calendario->VEVENT)->toHaveCount(1)
            ->and((string) $calendario->VEVENT[0]->SUMMARY)->toBe('Concerto in programma')
            /* Il nome del calendario dice che cosa si è sottoscritto: chi ha
               tre calendari della stessa città deve poterli distinguere. */
            ->and((string) $calendario->NAME)->toContain('Musica dal vivo');
    });

    it('si filtra per locale, che è il calendario che un locale mostra ai propri clienti', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Aurora']);

        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata al circolo'], venue: $locale);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata altrove']);

        $calendario = Reader::read($this->get('/eventi.ics?venue='.$locale->slug)->assertOk()->getContent());

        expect($calendario->VEVENT)->toHaveCount(1)
            ->and((string) $calendario->VEVENT[0]->SUMMARY)->toBe('Serata al circolo');
    });

    it('non porta dentro le bozze né le date passate', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $bozza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Bozza']);
        $bozza->event->update(['status' => EventStatus::Draft]);

        occurrenceAtLocal($city, $category, '2026-08-01 21:00:00', event: ['title' => 'Già passato']);

        $corpo = $this->get('/eventi.ics')->assertOk()->getContent();

        expect($corpo)->not->toContain('Bozza')->and($corpo)->not->toContain('Già passato');
    });

    it('il file di una singola data resta valido anche per un server CalDAV', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', '2026-09-06 23:00:00');

        $risposta = $this->get(route('events.calendar', [
            'slug' => $occorrenza->event->slug,
            'occurrence' => $occorrenza->getKey(),
        ]))->assertOk();

        expect(Reader::read($risposta->getContent())->validate(Node::PROFILE_CALDAV))->toBe([]);
    });
});

describe('/feed.rss', function (): void {
    it('produce un XML valido con le voci in programma', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concerto & sagra']);

        $risposta = $this->get('/feed.rss')->assertOk();

        $risposta->assertHeader('content-type', 'application/rss+xml; charset=utf-8');

        $xml = simplexml_load_string($risposta->getContent());

        expect($xml)->not->toBeFalse()
            ->and($xml->channel->item)->toHaveCount(1)
            /* Il titolo contiene una e commerciale: se il documento si è letto,
               la codifica regge. */
            ->and((string) $xml->channel->item[0]->title)->toContain('Concerto & sagra');
    });

    it('scrive le date per esteso: un lettore di feed apre il file giorni dopo', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concerto']);

        $corpo = (string) $this->get('/feed.rss')->assertOk()->getContent();

        expect($corpo)->toContain('domenica 6 settembre')->and($corpo)->not->toContain('— domani');
    });
});

describe('sottoscrizione dalla pagina', function (): void {
    it('la lista offre il proprio feed con i filtri accesi', function (): void {
        $city = testCity();
        $category = testCategory(['name' => 'Musica dal vivo']);

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        $this->get('/eventi?category='.$category->slug)
            ->assertOk()
            ->assertSee(__('feeds.subscribe'))
            ->assertSee(route('feeds.calendar', ['category' => $category->slug]), escape: false)
            ->assertSee(route('feeds.rss', ['category' => $category->slug]), escape: false);
    });

    it('la scheda del locale offre il calendario di quel locale', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $locale);

        $this->get('/locali/'.$locale->slug)
            ->assertOk()
            ->assertSee(route('feeds.calendar', ['venue' => $locale->slug]), escape: false);
    });
});

describe('widget incorporabile', function (): void {
    it('serve una pagina autonoma, incorniciabile e senza sessione', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Aurora']);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata al circolo'], venue: $locale);

        $risposta = $this->get('/widget/'.$locale->slug)->assertOk();

        $risposta->assertHeader('content-security-policy', 'frame-ancestors *')
            ->assertSee('Circolo Aurora')
            ->assertSee('Serata al circolo')
            /* Nessuna intestazione né navigazione del sito: è un riquadro. */
            ->assertDontSee(__('ui.nav.label'));

        expect($risposta->headers->getCookies())->toBe([]);
    });

    it('mostra solo le date future e solo quelle di quel locale', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Data futura'], venue: $locale);
        occurrenceAtLocal($city, $category, '2026-08-01 21:00:00', event: ['title' => 'Data passata'], venue: $locale);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Di un altro locale']);

        $this->get('/widget/'.$locale->slug)
            ->assertOk()
            ->assertSee('Data futura')
            ->assertDontSee('Data passata')
            ->assertDontSee('Di un altro locale');
    });

    it('rispetta il numero di date chiesto, entro i limiti', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

        foreach (range(6, 15) as $giorno) {
            occurrenceAtLocal($city, $category, '2026-09-'.$giorno.' 21:00:00', event: ['title' => 'Data numero '.$giorno], venue: $locale);
        }

        $this->get('/widget/'.$locale->slug.'?limite=2')
            ->assertOk()
            ->assertSee('Data numero 6')
            ->assertSee('Data numero 7')
            ->assertDontSee('Data numero 8');

        expect(WidgetEmbed::limit('999'))->toBe(WidgetEmbed::MAX_LIMIT)
            ->and(WidgetEmbed::limit('cascasse'))->toBe(WidgetEmbed::DEFAULT_LIMIT)
            ->and(WidgetEmbed::limit('0'))->toBe(1);
    });

    it('non esiste per un locale non approvato', function (): void {
        $city = testCity();

        $locale = Venue::factory()->create([
            'city_id' => $city->getKey(),
            'status' => VenueStatus::Draft,
        ]);

        $this->get('/widget/'.$locale->slug)->assertNotFound();
    });

    it('offre al gestore un codice da incollare che punta al proprio riquadro', function (): void {
        $city = testCity();
        $locale = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Aurora']);

        $codice = WidgetEmbed::snippet($locale);

        expect($codice)->toContain('<iframe')
            ->toContain(route('widget.show', ['venue' => $locale->slug]))
            ->toContain('loading="lazy"')
            ->toContain('title="');
    });
});
