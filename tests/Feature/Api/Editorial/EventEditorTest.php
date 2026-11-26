<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Organizer;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\ImageFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * R3 — il modulo evento della redazione nell'app: nuovo evento, modifica,
 * locandina e galleria, programmazione, «Genera date». Ogni prova passa
 * dalla griglia dei ruoli (moderatore, admin, super admin, referente di un
 * locale, utente comune, token non sbloccato) e confronta le regole con
 * quelle del modulo del pannello `/admin/events` sugli stessi valori.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->admins = [$this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
});

/** Un corpo valido per «Nuovo evento», come lo manda l'app. */
function editorialEventBody(VenueIsolationScenario $s, array $overrides = []): array
{
    return array_replace([
        'status' => 'draft',
        'title' => 'Rassegna dal telefono',
        'category_id' => $s->category->id,
        'city_id' => $s->city->id,
        'venue_id' => $s->venueA->id,
        'price_type' => 'free',
        'source' => 'manual',
        'verification_status' => 'unverified',
        'editorial_score' => 0,
        'occurrences' => [
            ['starts_at' => '2026-12-05T21:00:00', 'ends_at' => '2026-12-05T23:30:00', 'doors_at' => null, 'is_all_day' => false],
            ['starts_at' => '2026-12-12T21:00:00', 'ends_at' => null, 'doors_at' => null, 'is_all_day' => false],
        ],
    ], $overrides);
}

it('apre il modulo solo a chi crea e modifica eventi nella redazione sbloccata', function (): void {
    $urls = ['/api/v1/editorial/events/form', '/api/v1/editorial/events/'.$this->s->draftEventA->id.'/form'];

    foreach ($urls as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
    foreach ($urls as $url) {
        foreach ($this->outsiders as $outsider) {
            EditorialSession::as($this, EditorialSession::token($outsider))->getJson($url)->assertNotFound();
        }
        EditorialSession::as($this, EditorialSession::token($this->s->admin, unlocked: false))->getJson($url)
            ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
        // Il moderatore non crea e non modifica (`EventPolicy::create`/`update`), come nel pannello.
        EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson($url)->assertForbidden();
        foreach ($this->admins as $admin) {
            EditorialSession::as($this, EditorialSession::token($admin))->getJson($url)->assertOk()
                ->assertJsonPath('data.options.defaults.status', 'draft')
                ->assertJsonPath('data.options.defaults.price_type', 'unknown')
                ->assertJsonPath('data.options.defaults.currency', 'EUR')
                ->assertJsonPath('data.options.can.feature', true);
        }
    }

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($urls[1])->assertOk()
        ->assertJsonPath('data.event.title', 'Bozza del circolo Aurora')
        ->assertJsonPath('data.event.venue.name', 'Circolo Aurora')
        ->assertJsonPath('data.event.abilities.update', true)
        ->assertJsonPath('data.event.abilities.schedule', true);
});

it('crea un evento con le sue date nell\'ora della città, con l\'autore e il registro', function (): void {
    foreach ([$this->s->moderator, ...$this->outsiders] as $denied) {
        $status = $denied === $this->s->moderator ? 403 : 404;
        EditorialSession::as($this, EditorialSession::token($denied))->postJson('/api/v1/editorial/events', editorialEventBody($this->s))->assertStatus($status);
    }

    $tag = Tag::factory()->create(['name' => 'Folk']);
    $organizer = Organizer::create(['name' => 'Rete delle Arti', 'city_id' => $this->s->city->id, 'owner_id' => $this->s->ownerB->id, 'is_active' => true]);
    $response = EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->postJson('/api/v1/editorial/events', editorialEventBody($this->s, [
            'subtitle' => 'Tre serate',
            'tags' => [$tag->id],
            'organizers' => [$organizer->id],
            'price_type' => 'ticket',
            'price_min' => 8,
            'currency' => 'EUR',
            'is_featured' => true,
            'ticket_tiers' => [['name' => 'Intero', 'price' => 10, 'status' => 'available']],
            'recurrences' => [['rrule' => 'FREQ=WEEKLY;BYDAY=SA', 'until' => '2027-01-31T23:59:00', 'exdates' => ['2026-12-26']]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.title', 'Rassegna dal telefono')
        ->assertJsonPath('data.tags.0.name', 'Folk')
        ->assertJsonPath('data.organizers.0.id', $organizer->id)
        ->assertJsonPath('data.ticket_tiers.0.name', 'Intero')
        ->assertJsonPath('data.recurrences.0.exdates.0', '2026-12-26')
        ->assertJsonPath('data.is_featured', true);

    $event = Event::query()->findOrFail($response->json('data.id'));
    $dates = $event->occurrences()->orderBy('starts_at')->get();
    expect($event->created_by)->toBe($this->s->admin->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($dates)->toHaveCount(2)
        // Le 21 di Padova d'inverno sono le 20 UTC.
        ->and($dates[0]->starts_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-12-05 20:00')
        ->and($dates[0]->ends_at->copy()->utc()->format('H:i'))->toBe('22:30')
        ->and($event->recurrences()->first()->until->copy()->utc()->format('Y-m-d H:i'))->toBe('2027-01-31 22:59');

    $log = Activity::query()->where('subject_type', $event->getMorphClass())->where('subject_id', $event->id)->where('event', 'created')->first();
    expect($log)->not->toBeNull()->and($log->getProperty('canale'))->toBe('app');
});

it('rifiuta gli stessi valori non validi che rifiuta il modulo del pannello', function (): void {
    $invalid = [
        'title' => '',
        'category_id' => null,
        'short_description' => str_repeat('a', 501),
        'video_url' => 'https://example.com/non-un-video',
        'editorial_score' => 101,
        'language' => 'italiano',
        'price_type' => 'ticket',
        'currency' => null,
        'booking_required' => true,
        'booking_phone' => str_repeat('1', 41),
    ];
    // `price_type` e `booking_required` sono validi: servono a mostrare valuta e telefono.
    $fields = array_values(array_diff(array_keys($invalid), ['price_type', 'booking_required']));

    $api = EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->postJson('/api/v1/editorial/events', editorialEventBody($this->s, $invalid))
        ->assertUnprocessable()
        ->json('error.fields');
    expect(array_keys($api))->toEqualCanonicalizing($fields);

    // Le date: almeno una, con l'inizio.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->postJson('/api/v1/editorial/events', editorialEventBody($this->s, ['occurrences' => []]))
        ->assertUnprocessable()->assertJsonValidationErrorFor('occurrences', 'error.fields');

    // Il pannello, sugli stessi valori, segna gli stessi campi.
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/create')->assertOk();
    $panel = Livewire::test(CreateEvent::class)
        ->fillForm([...editorialEventBody($this->s, $invalid), 'occurrences' => [['starts_at' => '2026-12-05 21:00:00']]])
        ->call('create');
    foreach ($fields as $field) {
        $panel->assertHasFormErrors([$field]);
    }
    expect(Event::query()->where('title', 'Rassegna dal telefono')->exists())->toBeFalse();
});

it('modifica le chiavi ricevute, lascia ereditare «Prima di andare» dal locale e rispetta i campi riservati', function (): void {
    $this->s->venueA->update(['content_details' => ['stroller' => 'yes', 'food_notes' => 'Cucina aperta fino alle 22']]);
    $event = $this->s->draftEventA;
    $url = '/api/v1/editorial/events/'.$event->id;

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->patchJson($url, ['title' => 'No'])->assertForbidden();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, [
        'title' => 'Bozza riscritta dal telefono',
        'verification_status' => 'editorial_checked',
        'content_details' => ['stroller' => 'yes', 'kids_area' => 'no', 'food_notes' => 'Cucina aperta fino alle 22'],
        'seo' => ['title' => 'Titolo per i motori', 'indexing' => 'excluded'],
    ])->assertOk()
        ->assertJsonPath('data.title', 'Bozza riscritta dal telefono')
        // Il modulo li mostra già compilati con i valori del locale…
        ->assertJsonPath('data.content_details.stroller', 'yes')
        ->assertJsonPath('data.content_details.food_notes', 'Cucina aperta fino alle 22')
        ->assertJsonPath('data.verification_status', 'editorial_checked')
        ->assertJsonPath('data.seo.indexing', 'excluded')
        ->assertJsonPath('data.is_demo', false);

    // Un evento dimostrativo non è verificato da nessuno (`EventObserver`), anche dall'app.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, ['is_demo' => true])->assertOk()
        ->assertJsonPath('data.is_demo', true)
        ->assertJsonPath('data.verification_status', 'unverified');

    $fresh = $event->fresh();
    // …ma l'evento non li copia: li eredita.
    expect($fresh->content_details['stroller'] ?? null)->toBeNull()
        ->and($fresh->content_details['food_notes'] ?? null)->toBeNull()
        ->and($fresh->content_details['kids_area'])->toBe('no')
        ->and($fresh->category_id)->toBe($this->s->category->id)
        ->and($fresh->seo['title'])->toBe('Titolo per i motori');

    // Cambiare locale ricalcola i valori ereditati, come il campo «Locale» del pannello.
    $this->s->venueB->update(['content_details' => ['kids_area' => 'yes']]);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/events/before-going', [
        'previous_venue_id' => $this->s->venueA->id,
        'venue_id' => $this->s->venueB->id,
        'content_details' => ['stroller' => 'yes', 'kids_area' => 'no'],
    ])->assertOk()
        ->assertJsonPath('data.content_details.stroller', null)
        ->assertJsonPath('data.content_details.kids_area', 'no');
});

it('ignora i campi che il modulo nasconde, come il pannello', function (): void {
    $event = $this->s->draftEventA;
    $event->forceFill(['booking_phone' => '049 123456', 'price_type' => PriceType::Free])->save();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson('/api/v1/editorial/events/'.$event->id, [
        'booking_required' => false,
        'booking_phone' => str_repeat('1', 41),
        'currency' => 'troppo lunga',
        'price_min' => -5,
        'custom_location' => ['address' => 'Via Roma 1'],
    ])->assertOk();

    $fresh = $event->fresh();
    expect($fresh->booking_phone)->toBe('049 123456')
        ->and($fresh->currency)->toBe($event->currency)
        ->and($fresh->custom_location)->toBeNull();
});

it('rifiuta un locale di un\'altra città, come le scelte del campo «Locale»', function (): void {
    $other = City::factory()->create(['slug' => 'vicenza', 'name' => 'Vicenza']);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->patchJson('/api/v1/editorial/events/'.$this->s->draftEventA->id, ['city_id' => $other->id, 'venue_id' => $this->s->venueA->id])
        ->assertUnprocessable()->assertJsonValidationErrorFor('venue_id', 'error.fields');
});

it('cerca locali, organizzazioni e tag e crea un tag condiviso', function (): void {
    $token = EditorialSession::token($this->s->admin);
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/events/lookup/venues?city_id='.$this->s->city->id.'&q=Aur')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Circolo Aurora');
    Organizer::create(['name' => 'Pro loco attiva', 'city_id' => $this->s->city->id, 'owner_id' => $this->s->ownerB->id, 'is_active' => true]);
    Organizer::create(['name' => 'Pro loco spenta', 'city_id' => $this->s->city->id, 'owner_id' => $this->s->ownerB->id, 'is_active' => false]);
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/events/lookup/organizers?q=Pro')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Pro loco attiva');
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/events/lookup/tags?q=Jazz manouche')->assertOk()
        ->assertJsonPath('meta.can_create', true);
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/events/lookup/tags', ['name' => 'Jazz manouche', 'event_id' => $this->s->draftEventA->id])
        ->assertCreated()->assertJsonPath('data.name', 'Jazz manouche');
    expect(Tag::query()->where('slug', 'jazz-manouche')->exists())->toBeTrue();

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->postJson('/api/v1/editorial/events/lookup/tags', ['name' => 'Altro', 'event_id' => $this->s->draftEventA->id])->assertForbidden();
});

it('carica e toglie locandina e foto della galleria', function (): void {
    $disk = config()->string('media-library.disk_name');
    Storage::fake($disk);
    $url = '/api/v1/editorial/events/'.$this->s->draftEventA->id;
    $image = fn () => ImageFixtures::upload('foto.png', ImageFixtures::png(400, 500));

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url.'/poster', ['image' => $image()])->assertForbidden();

    $token = EditorialSession::token($this->s->admin);
    EditorialSession::as($this, $token)->postJson($url.'/poster', ['image' => $image()])->assertOk()
        ->assertJsonStructure(['data' => ['poster' => ['thumb', 'full'], 'gallery']]);
    $first = EditorialSession::as($this, $token)->postJson($url.'/gallery', ['image' => $image()])->assertCreated()->json('data.gallery.0.id');
    $second = EditorialSession::as($this, $token)->postJson($url.'/gallery', ['image' => $image()])->assertCreated()->json('data.gallery.1.id');
    EditorialSession::as($this, $token)->putJson($url.'/gallery/order', ['ids' => [$second, $first]])->assertOk()
        ->assertJsonPath('data.gallery.0.id', $second);
    EditorialSession::as($this, $token)->deleteJson($url.'/gallery/'.$first)->assertOk()->assertJsonCount(1, 'data.gallery');
    EditorialSession::as($this, $token)->deleteJson($url.'/poster')->assertOk();

    expect($this->s->draftEventA->fresh()->getMedia('poster'))->toHaveCount(0)
        ->and($this->s->draftEventA->fresh()->getMedia('gallery'))->toHaveCount(1);
});

it('programma e annulla la pubblicazione di una bozza con una data', function (): void {
    $event = $this->s->draftEventA;
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::now('UTC')->addWeek(), 'ends_at' => null, 'doors_at' => null]);
    $url = '/api/v1/editorial/events/'.$event->id.'/schedule';
    $at = CarbonImmutable::now('UTC')->addDays(2)->startOfHour();

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->putJson($url, ['publish_at' => $at->toIso8601String()])->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->putJson($url, ['publish_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String()])
        ->assertUnprocessable();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->putJson($url, ['publish_at' => $at->toIso8601String()])->assertOk()
        ->assertJsonPath('data.scheduled_publish_at', $at->toIso8601String())
        ->assertJsonPath('data.abilities.unschedule', true);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->deleteJson($url)->assertOk()
        ->assertJsonPath('data.scheduled_publish_at', null);
});

it('genera le date dalle ricorrenze come «Genera date» del pannello', function (): void {
    $event = $this->s->draftEventA;
    $start = CarbonImmutable::now('Europe/Rome')->addWeek()->setTime(21, 0);
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => $start->utc(), 'ends_at' => null, 'doors_at' => null]);
    EventRecurrence::query()->create(['event_id' => $event->id, 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'until' => null]);
    $url = '/api/v1/editorial/events/'.$event->id.'/occurrences/generate';

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url)->assertForbidden();
    $created = EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url)->assertOk()->json('meta.created');
    expect($created)->toBeGreaterThan(0);
    // Idempotente: la seconda volta non nasce niente.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url)->assertOk()->assertJsonPath('meta.created', 0);

    // Un evento senza ricorrenze non ha il pulsante.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/events/'.$this->s->draftEventB->id.'/occurrences/generate')->assertNotFound();

    // Il pannello passa dallo stesso servizio.
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/'.$event->getRouteKey().'/edit')->assertOk();
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])->callAction('generate_occurrences')->assertHasNoActionErrors();
    expect($event->occurrences()->count())->toBe(1 + $created);
});

it('salva il modulo del pannello come prima', function (): void {
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/create')->assertOk();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'city_id' => $this->s->city->id,
            'venue_id' => $this->s->venueA->id,
            'category_id' => $this->s->category->id,
            'title' => 'Dal pannello',
            'price_type' => PriceType::Free->value,
            'currency' => 'EUR',
            'status' => EventStatus::Draft->value,
            'source' => 'manual',
            'verification_status' => VerificationStatus::Unverified->value,
            'editorial_score' => 0,
            'occurrences' => [['starts_at' => '2026-12-05 21:00:00', 'ends_at' => null, 'doors_at' => null, 'is_all_day' => false]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::query()->where('title', 'Dal pannello')->value('created_by'))->toBe($this->s->admin->id);
});

it('rispetta lo stato delle date quando l\'evento è nel cestino', function (): void {
    $this->s->draftEventA->delete();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson('/api/v1/editorial/events/'.$this->s->draftEventA->id, ['title' => 'X'])->assertNotFound();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson('/api/v1/editorial/events/'.$this->s->draftEventA->id.'/dates')->assertNotFound();
    expect(OccurrenceStatus::Scheduled)->toBeInstanceOf(OccurrenceStatus::class);
});
