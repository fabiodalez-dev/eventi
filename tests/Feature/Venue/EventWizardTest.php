<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Support\EventFields;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventFeature;
use App\Services\Seo\EditorialContent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * §10.2 — il wizard che deve costare **90 secondi**.
 *
 * Il percorso è quello vero: lo stesso componente Livewire che disegna il
 * pannello, con le stesse Policy, lo stesso observer che calcola
 * `business_date`, la stessa azione di pubblicazione.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

function wizardData(array $overrides = []): array
{
    return [
        'title' => 'Serata swing',
        'starts_at' => CarbonImmutable::now('Europe/Rome')->addDays(3)->setTime(21, 30)->format('Y-m-d H:i:s'),
        'category_id' => test()->scenario->category->getKey(),
        'price_type' => PriceType::Free->value,
        ...$overrides,
    ];
}

it('valida anche le chiamate dirette al salvataggio automatico', function (string $field, mixed $value): void {
    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData())
        ->set('data.'.$field, $value)
        ->call('saveDraft')
        ->assertHasErrors(['data.'.$field]);

    expect(Event::query()->where('venue_id', $this->venue->id)->where('title', 'Serata swing')->exists())->toBeFalse();
})->with([
    'titolo troppo lungo' => ['title', str_repeat('x', 256)],
    'categoria inesistente' => ['category_id', 99999999],
    'tipo prezzo arbitrario' => ['price_type', 'evil'],
    'prezzo negativo' => ['price_min', -1],
    'prezzo enorme' => ['price_max', 1000000],
    'URL eseguibile' => ['ticket_url', 'javascript:alert(1)'],
    'descrizione troppo lunga' => ['description', str_repeat('x', 50001)],
]);

it('crea e pubblica un evento con una sola data', function (): void {
    $this->venue->update(['auto_publish' => true]);

    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData())
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->where('title', 'Serata swing')->sole();

    expect($event->venue_id)->toBe($this->venue->getKey())
        ->and($event->city_id)->toBe($this->venue->city_id)
        ->and($event->created_by)->toBe($this->scenario->ownerA->getKey())
        ->and($event->source)->toBe(EventSource::Venue)
        ->and($event->status)->toBe(EventStatus::Published)
        ->and($event->published_at)->not->toBeNull()
        ->and($event->occurrences()->count())->toBe(1);

    $occurrence = $event->occurrences()->sole();

    // Le due colonne calcolate le scrive l'observer, non il wizard (§5 delle convenzioni).
    expect($occurrence->business_date)->not->toBeNull()
        ->and($occurrence->effective_ends_at)->not->toBeNull()
        ->and($occurrence->status)->toBe(OccurrenceStatus::Scheduled);
});

it('scrive l’ora nel fuso della città, non in quello del server', function (): void {
    // Il gestore digita 21:30: sul database deve finire l'istante UTC
    // corrispondente alle 21:30 di Padova, mai le 21:30 UTC (§8.1).
    $local = CarbonImmutable::now('Europe/Rome')->addDays(3)->setTime(21, 30);

    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData(['starts_at' => $local->format('Y-m-d H:i:s')]))
        ->call('create')
        ->assertHasNoFormErrors();

    $occurrence = Event::query()->where('title', 'Serata swing')->sole()->occurrences()->sole();

    expect(CarbonImmutable::instance($occurrence->starts_at)->setTimezone('Europe/Rome')->format('H:i'))
        ->toBe('21:30');
});

it('manda alla redazione l’evento di un locale che non pubblica da sé', function (): void {
    $this->venue->update(['auto_publish' => false]);

    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::query()->where('title', 'Serata swing')->sole()->status)
        ->toBe(EventStatus::Pending);
});

it('salva la bozza a ogni passo e non crea un secondo evento alla fine', function (): void {
    $component = Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Serata swing'])
        ->call('saveDraft');

    $draft = Event::query()->where('venue_id', $this->venue->getKey())->where('title', 'Serata swing')->sole();

    expect($draft->status)->toBe(EventStatus::Draft)
        ->and($draft->occurrences()->count())->toBe(0);

    $component->fillForm(wizardData())->call('create')->assertHasNoFormErrors();

    $events = Event::query()->where('venue_id', $this->venue->getKey())->where('title', 'Serata swing')->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->getKey())->toBe($draft->getKey());
});

it('non salva una bozza senza titolo', function (): void {
    Livewire::test(CreateEvent::class)->call('saveDraft');

    expect(Event::query()->where('venue_id', $this->venue->getKey())->whereNull('title')->count())->toBe(0);
});

it('genera tutte le date quando il gestore dice che si ripete', function (): void {
    $this->venue->update(['auto_publish' => true]);

    $start = CarbonImmutable::now('Europe/Rome')->next(Weekday::Thursday->isoNumber())->setTime(22, 0);

    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData([
            'title' => 'Giovedì jazz',
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Thursday->value],
            'repeat_until' => $start->addWeeks(4)->toDateString(),
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->where('title', 'Giovedì jazz')->sole();

    // Cinque giovedì: quello di partenza più i quattro fino alla data di fine.
    expect($event->recurrences()->count())->toBe(1)
        ->and($event->occurrences()->count())->toBe(5)
        ->and($event->recurrences()->sole()->rrule)->toBe('FREQ=WEEKLY;BYDAY=TH');

    $weekdays = $event->occurrences()->pluck('starts_at')
        ->map(fn ($instant) => CarbonImmutable::instance($instant)->setTimezone('Europe/Rome')->dayOfWeekIso)
        ->unique()
        ->all();

    expect($weekdays)->toBe([Weekday::Thursday->isoNumber()]);
});

it('impara le abitudini del locale per il prossimo evento', function (): void {
    expect($this->venue->default_event_settings)->toBeNull();

    Livewire::test(CreateEvent::class)
        ->fillForm(wizardData(['price_type' => PriceType::Ticket->value, 'price_min' => 12]))
        ->call('create')
        ->assertHasNoFormErrors();

    $settings = $this->venue->fresh()->default_event_settings;

    expect($settings['category_id'])->toBe($this->scenario->category->getKey())
        ->and($settings['price_type'])->toBe(PriceType::Ticket->value)
        ->and($settings['start_hour'])->toBe(21)
        ->and($settings['start_minute'])->toBe(30);
});

it('rifiuta un evento senza titolo, senza data e senza categoria', function (): void {
    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => null, 'starts_at' => null, 'category_id' => null])
        ->call('create')
        ->assertHasFormErrors(['title', 'starts_at', 'category_id']);
});

it('non lascia creare eventi a chi non gestisce il locale', function (): void {
    $this->actingAs($this->scenario->plainUser);

    expect(EventResource::canCreate())->toBeFalse();
});

it('non usa categorie disattivate', function (): void {
    Category::query()->whereKey($this->scenario->category->getKey())->update(['is_active' => false]);

    $options = EventFields::categories();

    expect($options)->not->toHaveKey($this->scenario->category->getKey());
});

it('prefills venue practical defaults and saves only event additions', function (): void {
    $feature = EventFeature::create(['name' => 'Guardaroba disponibile', 'icon' => 'check-circle']);
    $this->venue->update(['content_details' => ['accessibility' => 'no', 'feature_ids' => [$feature->id], 'practical_custom' => [
        ['label' => 'Ingresso laterale', 'icon' => 'map-pin', 'text' => 'Da via Roma'],
    ]]]);
    $page = Livewire::test(CreateEvent::class)
        ->assertFormSet(['content_details.accessibility' => 'no', 'content_details.feature_ids' => [$feature->id]])
        ->fillForm(wizardData())
        ->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Serata swing')->sole();
    expect($event->content_details['accessibility'] ?? null)->toBeNull()
        ->and($event->content_details['feature_ids'] ?? [])->toBe([]);
    $items = collect(app(EditorialContent::class)->details($event)['practical_items']);
    expect($items->where('label', 'Ingresso laterale'))->toHaveCount(1)
        ->and($items->where('label', 'Guardaroba disponibile'))->toHaveCount(1);
});
