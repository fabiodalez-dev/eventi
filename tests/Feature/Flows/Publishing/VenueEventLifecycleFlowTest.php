<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Admin\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Admin\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Flusso «il gestore e il suo cartellone»: dal pannello `/gestione` il
 * gestore crea un evento (singolo o ricorrente), lo corregge, annulla o
 * sposta una data. Ogni gesto ha un effetto visibile sul sito pubblico e su
 * chi aveva quella data in agenda.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Entra nel pannello del locale come il browser, poi prepara Livewire. */
function pubEntraInGestione(User $user, Venue $venue, string $path = '/eventi/nuovo'): void
{
    test()->actingAs($user)->get('/gestione/'.$venue->slug.$path)->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
}

function pubDatiEvento(array $overrides = []): array
{
    return [
        'title' => 'Serata swing al circolo',
        'starts_at' => '2026-09-17 21:30:00',
        'category_id' => test()->scenario->category->id,
        'price_type' => PriceType::Free->value,
        ...$overrides,
    ];
}

/** Una persona salva la data dall'app, come farebbe davvero. */
function pubSalvaDallApp(User $persona, EventOccurrence $data): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($persona);
    test()->postJson('/api/v1/me/saved', ['occurrence_id' => $data->id])->assertCreated();
    // Sanctum::actingAs sposta la guardia predefinita: si torna al sito.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

/**
 * Torna sul sito pubblico da anonimo. Nel processo di test lo scope di
 * tenancy che Filament mette sugli eventi resta attivo dopo una richiesta a
 * `/gestione`, mentre in produzione ogni richiesta parte pulita: senza
 * azzerarlo, la pagina pubblica vedrebbe solo gli eventi del locale.
 */
function pubSitoPubblico(): void
{
    pubFuoriDalPannello();
    auth()->logout();
}

function pubFuoriDalPannello(): void
{
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
}

function pubDateDiEvento(Event $event): Testable
{
    return Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
}

it('pubblica dal wizard un evento con una data e avvisa chi segue il locale', function (): void {
    $this->venue->update(['auto_publish' => true]);
    $seguace = User::factory()->create();
    $silenzioso = User::factory()->create();
    app(FollowSubject::class)($seguace, FollowableType::Venue, $this->venue->id);
    app(FollowSubject::class)($silenzioso, FollowableType::Venue, $this->venue->id, false);

    pubEntraInGestione($this->owner, $this->venue);
    Livewire::test(CreateEvent::class)->fillForm(pubDatiEvento())->call('create')->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Serata swing al circolo')->sole();
    $data = $evento->occurrences()->sole();
    expect($evento->status)->toBe(EventStatus::Published)
        ->and($evento->venue_id)->toBe($this->venue->id)
        ->and($evento->created_by)->toBe($this->owner->id)
        ->and($data->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-17 19:30')
        ->and($data->business_date->toDateString())->toBe('2026-09-17');

    $avvisi = ScheduledNotification::query()->ofType(NotificationType::VenueNewEvent->value)->get();
    expect($avvisi->pluck('user_id')->all())->toBe([$seguace->id])
        ->and($avvisi->first()->notifiable_id)->toBe($evento->id);
    // Anche lo staff del locale sa che l'evento è uscito.
    expect(ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $this->scenario->editorA->id])->sort()->values()->all());

    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Serata swing al circolo')->assertSee('Circolo Aurora');
});

it('manda alla redazione l’evento di un locale che non pubblica da sé, e lo mostra solo dopo l’approvazione', function (): void {
    pubEntraInGestione($this->owner, $this->venue);
    Livewire::test(CreateEvent::class)->fillForm(pubDatiEvento(['title' => 'Reading in giardino']))->call('create')->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Reading in giardino')->sole();
    expect($evento->status)->toBe(EventStatus::Pending);

    // Il gestore lo vede in anteprima (pagina del sito, fuori dal pannello), il pubblico no.
    pubFuoriDalPannello();
    $this->actingAs($this->owner)->get(route('events.preview', $evento))->assertOk()->assertSee('Reading in giardino');
    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();

    Filament::setTenant(null);
    $this->actingAs($this->scenario->admin)->get(AdminEventResource::getUrl('edit', ['record' => $evento], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminEditEvent::class, ['record' => $evento->getRouteKey()])->callAction('publish');

    expect($evento->fresh()->status)->toBe(EventStatus::Published)
        ->and($evento->fresh()->published_at)->not->toBeNull();
    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Reading in giardino');
});

it('genera tutte le date di un evento ricorrente creato dal wizard', function (): void {
    $this->venue->update(['auto_publish' => true]);
    // Giovedì 17 settembre 2026, ogni giovedì fino al 15 ottobre.
    pubEntraInGestione($this->owner, $this->venue);
    Livewire::test(CreateEvent::class)
        ->fillForm(pubDatiEvento([
            'title' => 'Giovedì jazz',
            'starts_at' => '2026-09-17 22:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Thursday->value],
            'repeat_until' => '2026-10-15',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Giovedì jazz')->sole();
    $date = $evento->occurrences()->orderBy('starts_at')->get();

    expect($evento->recurrences()->sole()->rrule)->toBe('FREQ=WEEKLY;BYDAY=TH')
        ->and($date)->toHaveCount(5)
        ->and($date->map(fn (EventOccurrence $o): string => $o->starts_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->all())
        ->toBe(['2026-09-17 22:00', '2026-09-24 22:00', '2026-10-01 22:00', '2026-10-08 22:00', '2026-10-15 22:00'])
        // Al cambio dell'ora legale (25 ottobre) l'ora locale resta le 22: qui sono tutte in CEST.
        // La prima data la scrive il wizard; le altre quattro le genera la regola.
        ->and($date->slice(1)->every(fn (EventOccurrence $o): bool => $o->recurrence_id === $evento->recurrences()->sole()->id && $o->is_exception === false))->toBeTrue()
        ->and($date->every(fn (EventOccurrence $o): bool => $o->status === OccurrenceStatus::Scheduled))->toBeTrue()
        ->and($date->pluck('url_number')->unique())->toHaveCount(5);

    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Giovedì jazz');
    // Ogni data ha la propria pagina.
    $this->get('/eventi/'.$evento->slug.'/'.$date[2]->url_number)->assertOk();
});

it('rimanda in revisione un evento online riscritto da un locale senza pubblicazione autonoma', function (): void {
    $evento = $this->scenario->publishedEventA;

    pubEntraInGestione($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['title' => 'Concerto riscritto dal gestore'])
        ->call('save')
        ->assertHasNoFormErrors();

    $evento->refresh();
    expect($evento->title)->toBe('Concerto riscritto dal gestore')
        ->and($evento->status)->toBe(EventStatus::Pending);
    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();

    // Un locale che pubblica da sé corregge e resta online.
    $this->venue->update(['auto_publish' => true]);
    $evento->update(['status' => EventStatus::Published]);
    pubEntraInGestione($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['title' => 'Concerto corretto e online'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($evento->fresh()->status)->toBe(EventStatus::Published);
    pubSitoPubblico();
    $this->get('/eventi/'.$evento->fresh()->slug)->assertOk()->assertSee('Concerto corretto e online');
});

it('annulla una data e avvisa solo chi l’aveva salvata, togliendo i promemoria', function (): void {
    $evento = $this->scenario->publishedEventA;
    $data = $this->scenario->occurrenceA;
    $chiSalva = User::factory()->create();
    $altro = User::factory()->create();
    pubSalvaDallApp($chiSalva, $data);

    $promemoria = ScheduledNotification::query()->ofType(NotificationType::EventReminder->value)->where('user_id', $chiSalva->id)->get();
    expect($promemoria)->not->toBeEmpty();

    pubEntraInGestione($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    pubDateDiEvento($evento)
        ->callTableAction('cancel_occurrence', $data)
        ->assertHasNoTableActionErrors();

    expect($data->fresh()->status)->toBe(OccurrenceStatus::Cancelled);

    $annullamento = ScheduledNotification::query()->ofType(NotificationType::EventCancelled->value)->sole();
    expect($annullamento->user_id)->toBe($chiSalva->id)
        ->and($annullamento->notifiable_id)->toBe($data->id)
        ->and($annullamento->status)->toBe(NotificationStatus::Pending)
        ->and(ScheduledNotification::query()->where('user_id', $altro->id)->exists())->toBeFalse();

    // I promemoria di una serata che non c'è più non partono.
    foreach ($promemoria as $riga) {
        expect($riga->fresh()->status)->toBe(NotificationStatus::Cancelled);
    }

    // Un secondo clic non manda un secondo avviso: l'azione non è più offerta.
    pubDateDiEvento($evento)->assertTableActionHidden('cancel_occurrence', $data);
});

it('sposta una data, riprogramma i promemoria e avvisa chi l’aveva salvata', function (): void {
    $evento = $this->scenario->publishedEventA;
    $data = $this->scenario->occurrenceA; // 11 settembre, 21:00 a Padova
    $chiSalva = User::factory()->create();
    pubSalvaDallApp($chiSalva, $data);
    $promemoria = ScheduledNotification::query()->ofType(NotificationType::EventReminder->value)->where('user_id', $chiSalva->id)->firstOrFail();
    $ore = (int) $promemoria->context('hours', 0);
    $quantiPromemoria = ScheduledNotification::query()->ofType(NotificationType::EventReminder->value)->where('user_id', $chiSalva->id)->count();

    pubEntraInGestione($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    pubDateDiEvento($evento)
        ->callTableAction('edit', $data, data: ['starts_at' => '2026-09-18 22:00:00'])
        ->assertHasNoTableActionErrors();

    $data->refresh();
    expect($data->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-18 20:00')
        ->and($data->previous_starts_at?->utc()->format('Y-m-d H:i'))->toBe('2026-09-11 19:00')
        ->and($data->business_date->toDateString())->toBe('2026-09-18');

    $avviso = ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)->sole();
    expect($avviso->user_id)->toBe($chiSalva->id)
        ->and($avviso->context('previous_starts_at'))->toBe('2026-09-11T19:00:00+00:00')
        ->and($avviso->context('starts_at'))->toBe('2026-09-18T20:00:00+00:00');

    // Riprogrammare non è duplicare: lo stesso promemoria, spostato.
    expect(ScheduledNotification::query()->ofType(NotificationType::EventReminder->value)->where('user_id', $chiSalva->id)->count())->toBe($quantiPromemoria)
        ->and($promemoria->fresh()->send_at->utc()->format('Y-m-d H:i'))
        ->toBe(CarbonImmutable::parse('2026-09-18 20:00', 'UTC')->subHours($ore)->format('Y-m-d H:i'));

    pubSitoPubblico();
    $this->get('/eventi/'.$evento->slug.'/'.$data->url_number)->assertOk();
});

it('annulla da una data in poi tutta la serie ricorrente, lasciando intatte le precedenti', function (): void {
    $this->venue->update(['auto_publish' => true]);
    pubEntraInGestione($this->owner, $this->venue);
    Livewire::test(CreateEvent::class)
        ->fillForm(pubDatiEvento([
            'title' => 'Martedì cantautori',
            'starts_at' => '2026-09-15 21:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Tuesday->value],
            'repeat_until' => '2026-10-06',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Martedì cantautori')->sole();
    $date = $evento->occurrences()->orderBy('starts_at')->get();
    expect($date)->toHaveCount(4);

    $chiSalva = User::factory()->create();
    pubSalvaDallApp($chiSalva, $date[3]);

    pubEntraInGestione($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    pubDateDiEvento($evento)
        ->callTableAction('cancel_occurrence', $date[1], data: ['scope' => OccurrenceScope::Series->value])
        ->assertHasNoTableActionErrors();

    expect($date->map(fn (EventOccurrence $o) => $o->fresh()->status)->all())->toBe([
        OccurrenceStatus::Scheduled, OccurrenceStatus::Cancelled, OccurrenceStatus::Cancelled, OccurrenceStatus::Cancelled,
    ]);
    expect(ScheduledNotification::query()->ofType(NotificationType::EventCancelled->value)->where('user_id', $chiSalva->id)->sole()->notifiable_id)
        ->toBe($date[3]->id);
});
