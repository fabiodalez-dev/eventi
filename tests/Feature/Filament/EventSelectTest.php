<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Support\EventOptions;
use App\Filament\Support\EventSelect;
use App\Filament\Support\OccurrenceSelect;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;

/*
 * Il selettore di eventi dei pannelli (`EventSelect`, `EventOptions`,
 * `OccurrenceSelect`). Il 19 novembre 2026 è un giovedì: il 21 è sabato.
 */

/** Un modulo di prova con il selettore ristretto agli eventi di un locale. */
class EventSelectProbeForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public static int $venueId = 0;

    public static int $eventId = 0;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            EventSelect::make('event_id', fn () => Event::query()->where('venue_id', self::$venueId)),
            OccurrenceSelect::make('occurrence_id', fn () => EventOccurrence::query()->where('event_id', self::$eventId)),
        ])->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

/** Una tabella di prova delle date, filtrabile per evento. */
class EventSelectProbeTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public static int $venueId = 0;

    public function table(Table $table): Table
    {
        return $table
            ->query(EventOccurrence::query())
            ->columns([TextColumn::make('id')])
            ->filters([EventSelect::filter('event', fn () => Event::query()->where('venue_id', self::$venueId))]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    $this->city = testCity();
    $this->category = Category::factory()->create();
    $this->creator = User::factory()->create();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Officina dei Portici']);
    $this->other = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro Lontano']);
    EventSelectProbeForm::$venueId = $this->venue->id;
    EventSelectProbeTable::$venueId = $this->venue->id;

    $this->makeEvent = function (string $title, array $starts, ?Venue $venue = null, ?City $city = null): Event {
        $event = Event::factory()->create([
            'title' => $title,
            'city_id' => ($city ?? $this->city)->id,
            'venue_id' => ($venue ?? $this->venue)->id,
            'category_id' => $this->category->id,
            'created_by' => $this->creator->id,
            'status' => EventStatus::Published,
        ]);
        foreach ($starts as $start) {
            EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse($start, 'UTC')]);
        }

        return $event->fresh();
    };
});

it('mostra locandina, titolo, data leggibile nel fuso della città e locale', function (): void {
    ($this->makeEvent)('Jazz sotto i portici', ['2026-11-21 20:00:00']);

    $groups = EventOptions::grouped(Event::query());
    $html = implode('', $groups['Prossimi']);

    expect(array_keys($groups))->toBe(['Prossimi'])
        ->and($html)->toContain('<img')
        ->and($html)->toContain('Jazz sotto i portici')
        ->and($html)->toContain('sab 21 nov, 21:00 · Officina dei Portici')
        ->and($html)->not->toMatch('/#\d/')
        ->and($html)->not->toMatch('#\d{1,2}/\d{1,2}/\d{2,4}#');
});

it('usa il fuso della città dell’evento', function (): void {
    $newYork = City::factory()->create(['timezone' => 'America/New_York']);
    $venue = Venue::factory()->approved()->create(['city_id' => $newYork->id, 'name' => 'Blue Note']);
    ($this->makeEvent)('Jam session', ['2026-11-21 20:00:00'], $venue, $newYork);

    expect(implode('', EventOptions::grouped(Event::query())['Prossimi']))->toContain('sab 21 nov, 15:00 · Blue Note');
});

it('divide in prossimi dal più vicino e passati dal più recente', function (): void {
    $later = ($this->makeEvent)('Fra un mese', ['2026-12-19 20:00:00']);
    $soon = ($this->makeEvent)('Domani', ['2026-11-20 20:00:00']);
    $old = ($this->makeEvent)('Mesi fa', ['2026-06-01 20:00:00']);
    $recent = ($this->makeEvent)('Settimana scorsa', ['2026-11-12 20:00:00']);
    $mixed = ($this->makeEvent)('Già iniziato', ['2026-11-01 20:00:00', '2026-11-26 20:00:00', '2026-12-03 20:00:00', '2026-12-10 20:00:00']);
    $draft = ($this->makeEvent)('Senza date ancora', []);

    $groups = EventOptions::grouped(Event::query());

    expect(array_keys($groups))->toBe(['Prossimi', 'Passati'])
        ->and(array_keys($groups['Prossimi']))->toBe([$soon->id, $mixed->id, $later->id, $draft->id])
        ->and(array_keys($groups['Passati']))->toBe([$recent->id, $old->id])
        ->and($groups['Prossimi'][$mixed->id])->toContain('gio 26 nov, 21:00')->toContain('+ altre 2 date')
        ->and($groups['Prossimi'][$draft->id])->toContain('Senza date');

    $upcoming = EventOptions::grouped(Event::query(), upcomingOnly: true);
    expect(array_keys($upcoming))->toBe(['Prossimi'])
        ->and(array_keys($upcoming['Prossimi']))->toBe([$soon->id, $mixed->id, $later->id]);
});

it('non mostra il locale nel pannello del locale', function (): void {
    ($this->makeEvent)('Jazz sotto i portici', ['2026-11-21 20:00:00']);

    $html = implode('', EventOptions::grouped(Event::query(), showVenue: false)['Prossimi']);

    expect($html)->toContain('sab 21 nov, 21:00')->not->toContain('Officina dei Portici');
});

it('cerca per titolo e per nome del locale, al massimo trenta risultati', function (): void {
    ($this->makeEvent)('Jazz sotto i portici', ['2026-11-21 20:00:00']);
    ($this->makeEvent)('Teatro dei burattini', ['2026-11-22 20:00:00'], $this->other);
    ($this->makeEvent)('Cinema all’aperto', ['2026-11-23 20:00:00']);

    $byTitle = collect(EventOptions::grouped(Event::query(), 'jazz'))->flatMap(fn (array $group) => $group);
    $byVenue = collect(EventOptions::grouped(Event::query(), 'lontano'))->flatMap(fn (array $group) => $group);

    expect($byTitle)->toHaveCount(1)->and($byTitle->first())->toContain('Jazz sotto i portici')
        ->and($byVenue)->toHaveCount(1)->and($byVenue->first())->toContain('Teatro dei burattini');

    foreach (range(1, 20) as $i) {
        ($this->makeEvent)('Rassegna futura '.$i, ['2026-12-01 20:00:00']);
        ($this->makeEvent)('Rassegna passata '.$i, ['2026-10-01 20:00:00']);
    }
    $many = EventOptions::grouped(Event::query(), 'rassegna');
    expect(count($many['Prossimi']) + count($many['Passati'] ?? []))->toBe(30)
        ->and($many['Prossimi'])->toHaveCount(20);

    $default = EventOptions::grouped(Event::query());
    expect($default['Prossimi'])->toHaveCount(15)->and($default['Passati'])->toHaveCount(10);
});

it('scrive il titolo con l’escape', function (): void {
    $event = ($this->makeEvent)('Festa', ['2026-11-21 20:00:00']);
    // Scritto direttamente nel database: il modello pulirebbe già il titolo, qui conta la vista.
    Event::query()->whereKey($event->getKey())->update(['title' => '<script>alert(1)</script> Festa']);

    $html = implode('', EventOptions::grouped(Event::query())['Prossimi']);

    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Festa');
});

it('accetta un evento della query e rifiuta uno fuori, anche scritto a mano', function (): void {
    $mine = ($this->makeEvent)('Il mio evento', ['2026-11-21 20:00:00']);
    $theirs = ($this->makeEvent)('Evento di un altro', ['2026-11-21 20:00:00'], $this->other);

    Livewire::test(EventSelectProbeForm::class)
        ->set('data.event_id', $theirs->id)
        ->call('save')
        ->assertHasErrors(['data.event_id']);

    Livewire::test(EventSelectProbeForm::class)
        ->set('data.event_id', $mine->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved.event_id', $mine->id);

    expect(EventOptions::labelFor(Event::query()->where('venue_id', $this->venue->id), $theirs->id))->toBeNull()
        ->and(EventOptions::labelFor(Event::query()->where('venue_id', $this->venue->id), $mine->id))->toContain('Il mio evento');
});

it('restringe una tabella con il filtro per evento, e solo dentro la query', function (): void {
    $mine = ($this->makeEvent)('Il mio evento', ['2026-11-21 20:00:00']);
    $another = ($this->makeEvent)('Un altro mio evento', ['2026-11-28 20:00:00']);
    $theirs = ($this->makeEvent)('Evento di un altro', ['2026-11-21 20:00:00'], $this->other);

    $mineDates = $mine->occurrences()->get();
    $otherDates = $another->occurrences->merge($theirs->occurrences);

    Livewire::test(EventSelectProbeTable::class)
        ->filterTable('event', $mine->id)
        ->assertCanSeeTableRecords($mineDates)
        ->assertCanNotSeeTableRecords($otherDates);

    Livewire::test(EventSelectProbeTable::class)
        ->filterTable('event', $theirs->id)
        ->assertCanNotSeeTableRecords($theirs->occurrences);
});

it('mostra le date di un evento in parole, mai l’ID', function (): void {
    $event = ($this->makeEvent)('Rassegna', ['2026-11-21 20:00:00', '2026-11-12 20:00:00']);
    $elsewhere = EventOccurrence::factory()->create(['event_id' => $event->id, 'venue_id' => $this->other->id, 'starts_at' => CarbonImmutable::parse('2026-11-28 20:00:00', 'UTC')]);
    EventSelectProbeForm::$eventId = $event->id;

    $groups = OccurrenceSelect::grouped(EventOccurrence::query()->where('event_id', $event->id));
    $html = collect($groups)->flatten()->implode('');

    expect(array_keys($groups))->toBe(['Prossime date', 'Date passate'])
        ->and($html)->toContain('sab 21 nov, 21:00')->toContain('sab 28 nov, 21:00')->toContain('Teatro Lontano')
        ->and($html)->not->toContain('Officina dei Portici')
        ->and($html)->not->toMatch('/>\s*'.$elsewhere->id.'\s*</');

    $foreign = EventOccurrence::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-21 20:00:00', 'UTC')]);
    Livewire::test(EventSelectProbeForm::class)->set('data.occurrence_id', $foreign->id)->call('save')->assertHasErrors(['data.occurrence_id']);
    Livewire::test(EventSelectProbeForm::class)->set('data.occurrence_id', $elsewhere->id)->call('save')->assertHasNoErrors();

    $withEvent = collect(OccurrenceSelect::grouped(EventOccurrence::query()->where('event_id', $event->id), showEvent: true))->flatten()->implode('');
    expect($withEvent)->toContain('<img')->toContain('Rassegna')->toContain('sab 28 nov, 21:00 · Teatro Lontano');
});

it('regge cinquecento eventi senza una query per voce', function (): void {
    $count = function (int $events): int {
        DB::table('event_occurrences')->delete();
        DB::table('events')->delete();
        $rows = Event::factory()->count($events)->make([
            'city_id' => $this->city->id, 'venue_id' => $this->venue->id, 'category_id' => $this->category->id,
            'created_by' => $this->creator->id, 'status' => EventStatus::Published,
        ])->values()->map(fn (Event $event, int $i): array => [
            ...collect($event->getAttributes())->map(fn ($value) => is_array($value) ? json_encode($value) : $value)->all(),
            'slug' => 'evento-'.$events.'-'.$i, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows->chunk(100)->each(fn ($chunk) => DB::table('events')->insert($chunk->values()->all()));
        $ids = DB::table('events')->pluck('id');
        $dates = $ids->map(fn (int $id, int $i): array => [
            'event_id' => $id,
            'starts_at' => CarbonImmutable::parse('2026-11-19 20:00:00', 'UTC')->addDays($i % 2 === 0 ? $i % 40 : -($i % 40) - 1),
            'effective_ends_at' => CarbonImmutable::parse('2026-11-19 23:00:00', 'UTC'),
            'business_date' => '2026-11-19', 'url_number' => 1, 'status' => OccurrenceStatus::Scheduled->value, 'is_all_day' => false, 'is_exception' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dates->chunk(100)->each(fn ($chunk) => DB::table('event_occurrences')->insert($chunk->values()->all()));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $groups = EventOptions::grouped(Event::query());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(collect($groups)->flatten())->toHaveCount(min($events, 25));

        return $queries;
    };

    $few = $count(6);
    $many = $count(499);

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(8);
});
