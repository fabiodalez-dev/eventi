<?php

declare(strict_types=1);

use App\Actions\GenerateOccurrencesAction;
use App\Actions\InviteVenueMemberAction;
use App\Actions\RespondToVenueInvitation;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\VenueRole;
use App\Filament\Organizer\Resources\Events\Pages\CreateEvent as OrganizerCreateEvent;
use App\Filament\Organizer\Resources\Events\Pages\EditEvent as OrganizerEditEvent;
use App\Models\City;
use App\Models\Event;
use App\Models\EventFeature;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Events\OrganizerEventEditor;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * La rilettura di sicurezza dell'API di gestione (app): ogni regola sta nei
 * servizi e nelle policy condivisi, così vale anche nei pannelli.
 *
 * 1. Un co-organizzatore non sposta l'evento in un altro locale o città.
 * 2. La prima data non comincia anni fa, e la ricorrenza parte da oggi.
 * 3. Le caratteristiche di «Prima di andare» hanno un tetto e niente doppioni.
 * 4. Il locale non prende, pubblica da sé né duplica con il nome la bozza di
 *    un'organizzazione.
 * 5. Chi non gestisce la data riceve sempre 403 sull'annullamento, qualunque
 *    prenotazione indichi.
 * 6. L'invito di un locale con un provvedimento non si vede e non si accetta.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    $this->s = VenueIsolationScenario::make();
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

/** Un'organizzazione con un membro che la gestisce. @return array{0: Organizer, 1: User} */
function hardeningOrganizer(VenueIsolationScenario $s, string $name): array
{
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => $name, 'city_id' => $s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);

    return [$organizer, $owner];
}

/** La bozza dell'evento A, dell'organizzazione principale e co-organizzata da un'altra. @return array{0: Event, 1: Organizer, 2: User, 3: Organizer, 4: User} */
function coOrganizedDraft(VenueIsolationScenario $s): array
{
    [$primary, $primaryOwner] = hardeningOrganizer($s, 'Collettivo Portici');
    [$co, $coOwner] = hardeningOrganizer($s, 'Associazione Ponte Molino');
    $event = $s->draftEventA;
    $event->forceFill(['organizer_id' => $primary->id, 'description' => '<p>Tre sere di musica.</p>'])->saveQuietly();
    $event->organizers()->attach($co);

    return [$event->fresh(), $primary, $primaryOwner, $co, $coOwner];
}

it('non lascia al co-organizzatore spostare l’evento in un altro locale o città, dall’app', function (): void {
    [$event, $primary, $primaryOwner, $co, $coOwner] = coOrganizedDraft($this->s);
    $otherCity = City::factory()->create();
    Sanctum::actingAs($coOwner);
    $url = '/api/v1/management/organizers/'.$co->id.'/events/'.$event->id;

    $this->patchJson($url, ['venue_id' => $this->s->venueB->id])->assertUnprocessable()
        ->assertJsonValidationErrors(['venue_id'], 'error.fields');
    $this->patchJson($url, ['city_id' => $otherCity->id])->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id'], 'error.fields');
    expect($event->fresh()->venue_id)->toBe($this->s->venueA->id)
        ->and($event->fresh()->city_id)->toBe($this->s->city->id);

    // Il modulo intero, con il luogo che c'era già, passa: cambia solo il contenuto.
    $this->patchJson($url, ['venue_id' => $this->s->venueA->id, 'city_id' => $this->s->city->id, 'title' => 'Rassegna sotto i portici'])->assertOk();
    expect($event->fresh()->title)->toBe('Rassegna sotto i portici');

    // L'organizzazione principale sì.
    Sanctum::actingAs($primaryOwner);
    $this->patchJson('/api/v1/management/organizers/'.$primary->id.'/events/'.$event->id, ['venue_id' => $this->s->venueB->id])->assertOk();
    expect($event->fresh()->venue_id)->toBe($this->s->venueB->id);
});

it('rifiuta lo spostamento del co-organizzatore nel salvataggio condiviso col pannello, che lo lascia all’organizzazione principale', function (): void {
    [$event, $primary, $primaryOwner, $co, $coOwner] = coOrganizedDraft($this->s);

    // La regola che il pannello chiama prima di salvare (`EditEvent::mutateFormDataBeforeSave`).
    expect(fn () => app(OrganizerEventEditor::class)->prepareUpdate($event, $co, $coOwner, ['venue_id' => $this->s->venueB->id]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(OrganizerEventEditor::class)->prepareUpdate($event, $co, $coOwner, ['city_id' => City::factory()->create()->id]))
        ->toThrow(ValidationException::class);
    expect(app(OrganizerEventEditor::class)->prepareUpdate($event, $co, $coOwner, ['venue_id' => $this->s->venueA->id, 'title' => 'x'])[0])
        ->toMatchArray(['title' => 'x'])
        ->and(app(OrganizerEventEditor::class)->prepareUpdate($event, $primary, $primaryOwner, ['venue_id' => $this->s->venueB->id])[0])
        ->toMatchArray(['venue_id' => $this->s->venueB->id]);

    // Nel pannello dell'organizzazione principale città e locale restano modificabili.
    $this->actingAs($primaryOwner, 'web')->get(OrganizerEditEvent::getUrl(['record' => $event], panel: 'organizer', tenant: $primary))->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($primary);
    Livewire::test(OrganizerEditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormFieldIsEnabled('venue_id')
        ->assertFormFieldIsEnabled('city_id');
});

it('rifiuta una prima data di anni fa, nell’app e nel pannello, e accetta quella di pochi giorni fa', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $before = EventOccurrence::query()->count();

    $this->postJson($this->venueUrl.'/events', [
        'title' => 'Ogni giorno dal 2016', 'publish' => true, 'category_id' => $this->s->category->id, 'price_type' => 'free',
        'starts_at' => '2016-01-01T21:00:00+01:00',
        'repeat' => ['frequency' => 'daily'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['starts_at'], 'error.fields');
    expect(EventOccurrence::query()->count())->toBe($before);

    $this->postJson($this->venueUrl.'/events', [
        'title' => 'Serata di sabato scorso', 'publish' => true, 'category_id' => $this->s->category->id, 'price_type' => 'free',
        'starts_at' => '2026-08-29T21:00:00+02:00',
    ])->assertCreated();

    [$organizer, $owner] = hardeningOrganizer($this->s, 'Collettivo Portici');
    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/events', [
        'title' => 'Rassegna del 1900', 'description' => '<p>x</p>', 'city_id' => $this->s->city->id, 'venue_id' => $this->s->venueA->id,
        'category_id' => $this->s->category->id, 'price_type' => 'free', 'starts_at' => '1900-01-01T21:00:00+01:00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['starts_at'], 'error.fields');

    $this->actingAs($owner, 'web')->get('/organizza/'.$organizer->slug.'/eventi/create')->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerCreateEvent::class)
        ->fillForm([
            'title' => 'Rassegna del 2016', 'description' => '<p>x</p>', 'city_id' => $this->s->city->id, 'venue_id' => $this->s->venueA->id,
            'category_id' => $this->s->category->id, 'price_type' => 'free', 'starts_at' => '2016-01-01 21:00:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['starts_at']);
    expect(Event::query()->where('title', 'Rassegna del 2016')->exists())->toBeFalse();
});

it('materializza la ricorrenza da oggi, senza le serate già passate', function (): void {
    // Il primo giovedì della serie è a luglio; oggi è martedì 1 settembre.
    $template = EventOccurrence::factory()->create([
        'event_id' => $this->s->draftEventA->id,
        'starts_at' => CarbonImmutable::parse('2026-07-02 19:00:00', 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);
    $recurrence = EventRecurrence::factory()->create(['event_id' => $this->s->draftEventA->id, 'rrule' => 'FREQ=WEEKLY;BYDAY=TH;UNTIL=20261001T000000Z']);

    $created = app(GenerateOccurrencesAction::class)($recurrence);

    $starts = EventOccurrence::query()->where('event_id', $this->s->draftEventA->id)->orderBy('starts_at')->pluck('starts_at')
        ->map(fn ($start): string => CarbonImmutable::parse($start)->utc()->format('Y-m-d'))->all();
    expect($created)->toBe(4)
        ->and($starts)->toBe(['2026-07-02', '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24'])
        ->and($template->fresh())->not->toBeNull();
});

it('mette un tetto e vieta i doppioni nelle caratteristiche dell’evento e del locale', function (): void {
    $feature = EventFeature::create(['name' => 'Guardaroba custodito', 'icon' => 'check-circle']);
    Sanctum::actingAs($this->s->ownerA);
    $eventUrl = $this->venueUrl.'/events/'.$this->s->draftEventA->id;

    $this->patchJson($eventUrl, ['content_details' => ['feature_ids' => [$feature->id, $feature->id]]])->assertUnprocessable()
        ->assertJsonValidationErrors(['content_details.feature_ids.0'], 'error.fields');
    $this->patchJson($eventUrl, ['content_details' => ['feature_ids' => range(1, 61)]])->assertUnprocessable()
        ->assertJsonValidationErrors(['content_details.feature_ids'], 'error.fields');
    $this->patchJson($eventUrl, ['content_details' => ['feature_ids' => [$feature->id]]])->assertOk();

    $this->patchJson($this->venueUrl.'/profile', ['content_details' => ['feature_ids' => [$feature->id, $feature->id]]])->assertUnprocessable()
        ->assertJsonValidationErrors(['content_details.feature_ids.0'], 'error.fields');
    $this->patchJson($this->venueUrl.'/profile', ['content_details' => ['feature_ids' => range(1, 61)]])->assertUnprocessable()
        ->assertJsonValidationErrors(['content_details.feature_ids'], 'error.fields');
});

it('non fa riprendere, pubblicare da sé né duplicare con il nome la bozza di un’organizzazione al locale', function (): void {
    [$organizer] = hardeningOrganizer($this->s, 'Collettivo Portici');
    $draft = $this->s->draftEventA;
    $draft->forceFill(['organizer_id' => $organizer->id])->saveQuietly();
    EventOccurrence::create(['event_id' => $draft->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 19:00:00', 'UTC')]);
    $this->s->venueA->update(['auto_publish' => true]);
    Sanctum::actingAs($this->s->ownerA);

    // `draft_id` non la trova: è dell'organizzazione.
    $this->postJson($this->venueUrl.'/events', [
        'title' => 'Presa dal locale', 'publish' => true, 'draft_id' => $draft->id, 'category_id' => $this->s->category->id, 'price_type' => 'free',
        'starts_at' => '2026-09-20T21:00:00+02:00',
    ])->assertNotFound();
    expect($draft->fresh()->status)->toBe(EventStatus::Draft)
        ->and($draft->fresh()->title)->toBe('Bozza del circolo Aurora');

    // «Pubblica» del locale (lo stesso servizio del pannello): va alla redazione.
    $this->postJson($this->venueUrl.'/events/'.$draft->id.'/publish')->assertOk()->assertJsonPath('meta.outcome', 'pending');
    expect($draft->fresh()->status)->toBe(EventStatus::Pending);

    // La copia chiesta dal locale è del locale.
    $copy = $this->postJson($this->venueUrl.'/events/'.$draft->id.'/duplicate')->assertCreated();
    expect(Event::query()->findOrFail($copy->json('data.id'))->organizer_id)->toBeNull();

    // Una bozza del locale si riprende come prima.
    $own = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueA->id,
        'created_by' => $this->s->ownerA->id, 'title' => 'Bozza del locale', 'status' => EventStatus::Draft]);
    $this->postJson($this->venueUrl.'/events', ['title' => 'Bozza ripresa', 'draft_id' => $own->id])
        ->assertOk()->assertJsonPath('data.id', $own->id)->assertJsonPath('data.title', 'Bozza ripresa');
});

it('risponde 403 a chi non gestisce la data, che la prenotazione esista o no', function (): void {
    $this->s->venueA->forceFill(['ticketing_enabled' => true])->saveQuietly();
    $date = $this->s->occurrenceA;
    $date->forceFill(['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 4])->saveQuietly();
    $booking = app(TicketingService::class)->reserve(User::factory()->create(), $date->fresh(), ['Ada'], (string) Str::uuid(), false);
    $base = '/api/v1/management/dates/'.$date->id.'/bookings/';

    foreach ([$this->s->plainUser, $this->s->ownerB] as $stranger) {
        Sanctum::actingAs($stranger);
        $this->postJson($base.$booking->id.'/cancel')->assertForbidden();
        $this->postJson($base.($booking->id + 1000).'/cancel')->assertForbidden();
        $this->postJson($base.($booking->id + 1000).'/cancel', ['ticket_id' => 'x'])->assertForbidden();
    }
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // Chi gestisce la data distingue ancora: la prenotazione che non c'è è 404.
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($base.($booking->id + 1000).'/cancel')->assertNotFound();
    $this->postJson($base.$booking->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('non mostra né fa accettare l’invito di un locale con un provvedimento', function (): void {
    $invited = User::factory()->create();
    app(InviteVenueMemberAction::class)->execute($this->s->venueA, $invited->email, 'Invitata', VenueRole::Editor);
    $sent = CarbonImmutable::parse((string) DB::table('venue_user')->where('venue_id', $this->s->venueA->id)->where('user_id', $invited->id)->value('invited_at'))->getTimestamp();
    $this->s->venueA->forceFill(['status' => 'suspended'])->saveQuietly();
    Sanctum::actingAs($invited);

    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(0, 'data.venues');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.invitations', 0);
    $this->postJson('/api/v1/me/invitations/venues/'.$this->s->venueA->id, ['decision' => 'accept', 'sent' => $sent])->assertNotFound();
    expect(DB::table('venue_user')->where('venue_id', $this->s->venueA->id)->where('user_id', $invited->id)->value('accepted_at'))->toBeNull()
        ->and(app(RespondToVenueInvitation::class)->accept($this->s->venueA->fresh(), $invited, $sent))->toBeFalse();

    // Tolto il provvedimento, l'invito ancora valido torna.
    $this->s->venueA->forceFill(['status' => 'approved'])->saveQuietly();
    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(1, 'data.venues');
    $this->postJson('/api/v1/me/invitations/venues/'.$this->s->venueA->id, ['decision' => 'accept', 'sent' => $sent])->assertOk()
        ->assertJsonPath('data.accepted', true);
});
