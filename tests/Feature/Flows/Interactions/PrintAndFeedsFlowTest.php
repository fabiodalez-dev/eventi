<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Sabre\VObject\Reader;

/*
 * Quello che dalle schede si porta via: la locandina della data, il
 * programma settimanale del locale, i calendari `.ics` da sottoscrivere e il
 * riquadro da incorporare nel sito del locale.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory(['name' => 'Musica dal vivo']);
    // Giovedì: la settimana va da lunedì 9 a domenica 15 novembre.
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $this->organizer = Organizer::create(['name' => 'Archi Riuniti', 'city_id' => $this->city->id, 'is_active' => true]);
    $this->quartetto = occurrenceAtLocal($this->city, $this->category, '2026-11-13 21:00', '2026-11-13 23:00',
        event: ['title' => 'Quartetto d\'archi', 'organizer_id' => $this->organizer->id], venue: $this->venue);
    $this->dopo = occurrenceAtLocal($this->city, $this->category, '2026-11-17 21:00', event: ['title' => 'Jam session del martedì'], venue: $this->venue);
    $teatro = testCategory(['name' => 'Teatro e danza']);
    $this->altrove = occurrenceAtLocal($this->city, $teatro, '2026-11-14 20:30', event: ['title' => 'Prosa in un altro teatro']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** @return list<string> i titoli degli eventi di un file `.ics`, dopo averlo validato. */
function intFlowCalendarTitles(string $ics): array
{
    $calendar = Reader::read($ics);
    expect($calendar->validate())->toBe([]);
    $titles = [];
    foreach ($calendar->select('VEVENT') as $vevent) {
        $titles[] = (string) $vevent->SUMMARY;
    }
    sort($titles);

    return $titles;
}

it('dalla scheda si scarica la locandina della data e dalla scheda del locale il programma della settimana scelta', function (): void {
    $event = $this->quartetto->event;
    $poster = route('events.poster', ['slug' => $event->slug, 'occurrence' => $this->quartetto->url_number]);
    $datiLocandina = null;
    View::composer('pdf.event-poster', function ($view) use (&$datiLocandina): void {
        $datiLocandina = $view->getData();
    });

    $this->get(route('events.show', ['slug' => $event->slug]))->assertOk()->assertSee($poster, false);
    $pdf = $this->get($poster)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toBe('attachment; filename="quartetto-darchi-2026-11-13.pdf"')
        ->and(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-')
        ->and($datiLocandina['occurrence']->id)->toBe($this->quartetto->id)
        ->and($datiLocandina['event']->venue->name)->toBe('Circolo Aurora');

    // Numero di data che non è di questo evento, o evento non pubblico: niente locandina.
    $this->get(route('events.poster', ['slug' => $event->slug, 'occurrence' => 9]))->assertNotFound();
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', event: ['status' => 'draft'], venue: $this->venue);
    $this->get(route('events.poster', ['slug' => $bozza->event->slug, 'occurrence' => $bozza->url_number]))->assertNotFound();

    // Il programma della settimana corrente: solo le date di questo locale, da lunedì a domenica.
    $datiProgramma = null;
    View::composer('pdf.venue-programme', function ($view) use (&$datiProgramma): void {
        $datiProgramma = $view->getData();
    });
    $programme = route('venues.programme', ['slug' => $this->venue->slug]);
    $this->get(route('venues.show', ['slug' => $this->venue->slug]))->assertOk()->assertSee($programme, false);
    $questa = $this->get($programme)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($questa->headers->get('Content-Disposition'))->toBe('inline; filename="circolo-aurora-programma-2026-11-09.pdf"')
        ->and(substr((string) $questa->getContent(), 0, 5))->toBe('%PDF-');
    /** @var Collection<string, Collection<int, EventOccurrence>> $giorni */
    $giorni = $datiProgramma['days'];
    expect($giorni->keys()->all())->toBe(['2026-11-13'])
        ->and($giorni->flatten()->map(fn (EventOccurrence $o): string => $o->event->title)->all())->toBe(['Quartetto d\'archi']);

    // La settimana dopo, scelta con `?settimana=` anche a metà settimana.
    $prossima = $this->get($programme.'?settimana=2026-11-18')->assertOk();
    expect($prossima->headers->get('Content-Disposition'))->toBe('inline; filename="circolo-aurora-programma-2026-11-16.pdf"')
        ->and($datiProgramma['days']->flatten()->map(fn (EventOccurrence $o): string => $o->event->title)->all())->toBe(['Jam session del martedì']);

    // Un locale non approvato non ha programma.
    $chiuso = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $this->get(route('venues.programme', ['slug' => $chiuso->slug]))->assertNotFound();
});

it('calendari pubblici di locale, organizzatore e categoria si sottoscrivono validi, e il widget del locale si incorpora senza cookie', function (): void {
    // Calendario del locale, linkato dalla sua scheda.
    $venueIcs = route('venues.calendar', ['slug' => $this->venue->slug]);
    $this->get(route('venues.show', ['slug' => $this->venue->slug]))->assertOk()->assertSee($venueIcs, false);
    $risposta = $this->get($venueIcs)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($risposta->headers->get('Content-Disposition'))->toBe('inline; filename="'.$this->venue->slug.'.ics"')
        ->and(intFlowCalendarTitles((string) $risposta->getContent()))->toBe(['Jam session del martedì', 'Quartetto d\'archi']);

    // Calendario dell'organizzatore, linkato dalla sua scheda: solo i suoi eventi.
    $organizerIcs = route('organizers.calendar', ['slug' => $this->organizer->slug]);
    $this->get(route('organizers.show', ['slug' => $this->organizer->slug]))->assertOk()->assertSee($organizerIcs, false);
    expect(intFlowCalendarTitles((string) $this->get($organizerIcs)->assertOk()->getContent()))->toBe(['Quartetto d\'archi']);

    // Calendario di una categoria: le due date di musica, non la prosa.
    $categoryIcs = route('categories.calendar', ['slug' => $this->category->slug]);
    expect(intFlowCalendarTitles((string) $this->get($categoryIcs)->assertOk()->getContent()))->toBe(['Jam session del martedì', 'Quartetto d\'archi']);
    $teatro = $this->altrove->event->category;
    expect(intFlowCalendarTitles((string) $this->get(route('categories.calendar', ['slug' => $teatro->slug]))->getContent()))->toBe(['Prosa in un altro teatro']);

    // Un organizzatore spento, un locale in bozza o slug inventati non hanno calendario.
    $this->organizer->update(['is_active' => false]);
    $this->get($organizerIcs)->assertNotFound();
    $this->get(route('categories.calendar', ['slug' => 'categoria-inesistente']))->assertNotFound();

    // Il widget incorporabile: solo le prossime date del locale, incorniciabile, senza sessione.
    $widget = $this->get('/widget/'.$this->venue->slug)->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
        ->assertSee('Circolo Aurora')
        ->assertSee('Quartetto d&#039;archi', false)
        ->assertSee('Jam session del martedì')
        ->assertDontSee('Prosa in un altro teatro');
    expect($widget->headers->getCookies())->toBe([]);
    $this->get('/widget/'.$this->venue->slug.'?limite=1')->assertOk()
        ->assertSee('Quartetto d&#039;archi', false)->assertDontSee('Jam session del martedì');

    // Passata la serata del quartetto, il widget mostra solo quel che resta.
    freezeLocal($this->city, '2026-11-15 12:00');
    $this->get('/widget/'.$this->venue->slug)->assertOk()->assertDontSee('Quartetto d&#039;archi', false)->assertSee('Jam session del martedì');

    $bozza = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $this->get('/widget/'.$bozza->slug)->assertNotFound();
    $this->get(route('venues.calendar', ['slug' => $bozza->slug]))->assertNotFound();
    expect(Event::query()->count())->toBe(3);
});
