<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\TicketTierStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\FacebookImportDrafts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * Gli eventi nell'app di gestione (P1): elenco, scheda, creazione, modifica,
 * pubblicazione, duplica, programmazione, eliminazione, invio alla
 * redazione, avvisi sulla data, import da Facebook. Le regole sono quelle dei
 * pannelli, chiamate dagli stessi servizi (`VenueEventEditor`,
 * `OrganizerEventEditor`, `EventSubmission`).
 */
beforeEach(function (): void {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    $this->s = VenueIsolationScenario::make();
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function managedOrganizer(VenueIsolationScenario $s, ?User $owner = null): Organizer
{
    $owner ??= User::factory()->create();

    return Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
}

it('elenca gli eventi del locale con le schede del pannello', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $all = $this->getJson($this->venueUrl.'/events')->assertOk();
    expect(collect($all->json('data'))->pluck('id')->all())
        ->toContain($this->s->publishedEventA->id, $this->s->draftEventA->id)
        ->not->toContain($this->s->publishedEventB->id);
    expect($all->json('data.0.id'))->toBe($this->s->publishedEventA->id)
        ->and($all->json('data.0.next_starts_at'))->toBe('2026-09-11T19:00:00+00:00');

    $drafts = $this->getJson($this->venueUrl.'/events?tab=drafts')->assertOk();
    expect(collect($drafts->json('data'))->pluck('id')->all())->toBe([$this->s->draftEventA->id]);

    $upcoming = $this->getJson($this->venueUrl.'/events?tab=upcoming')->assertOk();
    expect(collect($upcoming->json('data'))->pluck('id')->all())->toBe([$this->s->publishedEventA->id]);

    $this->getJson($this->venueUrl.'/events?q=Bozza')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->venueUrl.'/events?status=published')->assertOk()->assertJsonPath('data.0.status.value', 'published');
    $this->getJson($this->venueUrl.'/events?tab=nonsense')->assertUnprocessable();
});

it('mostra all’organizzazione anche gli eventi co-organizzati', function (): void {
    $organizer = managedOrganizer($this->s);
    $member = User::factory()->create();
    $organizer->users()->attach($member);
    $this->s->publishedEventB->organizers()->attach($organizer);
    Sanctum::actingAs($member);

    $this->getJson('/api/v1/management/organizers/'.$organizer->id.'/events')->assertOk()
        ->assertJsonPath('data.0.id', $this->s->publishedEventB->id)
        ->assertJsonPath('data.0.venue.name', 'Teatro Belzoni');
});

it('apre la scheda solo per gli eventi del soggetto, con motivo del rifiuto e azioni', function (): void {
    $this->s->draftEventA->forceFill(['status' => EventStatus::Rejected, 'rejection_reason' => 'Manca il programma'])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->venueUrl.'/events/'.$this->s->draftEventA->id)->assertOk()
        ->assertJsonPath('data.status.value', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Manca il programma')
        ->assertJsonPath('data.abilities.publish', true)
        ->assertJsonPath('data.abilities.publish_needs_dates', true)
        ->assertJsonPath('data.abilities.submit', false)
        ->assertJsonPath('data.abilities.delete', true);

    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id)->assertOk()
        ->assertJsonPath('data.dates.upcoming.0.id', $this->s->occurrenceA->id)
        ->assertJsonPath('data.links.tickets_occurrence_id', $this->s->occurrenceA->id);

    // L'evento di un altro locale non esiste, sotto questo locale.
    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventB->id)->assertNotFound();
});

it('chiude la porta a chi non gestisce il soggetto e al locale sospeso', function (): void {
    Sanctum::actingAs($this->s->plainUser);
    $this->getJson($this->venueUrl.'/events')->assertNotFound();
    $this->postJson($this->venueUrl.'/events', ['title' => 'Intruso'])->assertNotFound();

    Sanctum::actingAs($this->s->ownerB);
    $this->patchJson($this->venueUrl.'/events/'.$this->s->draftEventA->id, ['title' => 'Preso'])->assertNotFound();

    $this->s->venueA->forceFill(['status' => 'suspended'])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->venueUrl.'/events')->assertNotFound();
    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/publish')->assertNotFound();

    expect($this->s->draftEventA->fresh()->title)->toBe('Bozza del circolo Aurora');
});

it('salva la bozza con il solo titolo, con la categoria di ripiego, e la seconda volta non ne crea un’altra', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $before = Event::query()->count();

    $first = $this->postJson($this->venueUrl.'/events', ['title' => 'Jazz al circolo'])->assertCreated()
        ->assertJsonPath('meta.outcome', 'draft')
        ->assertJsonPath('data.status.value', 'draft')
        ->assertJsonPath('data.category.id', $this->s->category->id);
    $id = $first->json('data.id');

    $this->postJson($this->venueUrl.'/events', ['title' => 'Jazz al circolo, seconda', 'draft_id' => $id])->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.title', 'Jazz al circolo, seconda');

    expect(Event::query()->count())->toBe($before + 1);
    $this->postJson($this->venueUrl.'/events', [])->assertUnprocessable()->assertJsonValidationErrors(['title'], 'error.fields');
    // La bozza di un altro locale non si riprende.
    $this->postJson($this->venueUrl.'/events', ['title' => 'x', 'draft_id' => $this->s->draftEventB->id])->assertNotFound();
});

it('pubblica il nuovo evento con la prima data e impara le abitudini, o lo manda alla redazione', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $tag = Tag::create(['name' => 'Jazz', 'slug' => 'jazz', 'is_approved' => true]);

    $this->s->venueA->update(['auto_publish' => true]);
    $published = $this->postJson($this->venueUrl.'/events', [
        'title' => 'Serata swing',
        'publish' => true,
        'category_id' => $this->s->category->id,
        'price_type' => 'ticket',
        'price_min' => 12,
        'starts_at' => '2026-09-18T21:30:00+02:00',
        'tags' => [$tag->id],
        'description' => '<p>Una <strong>serata</strong> di swing.</p>',
    ])->assertCreated()
        ->assertJsonPath('meta.outcome', 'published')
        ->assertJsonPath('data.status.value', 'published')
        ->assertJsonPath('data.tags.0.name', 'Jazz');
    $event = Event::query()->findOrFail($published->json('data.id'));
    expect($event->occurrences()->first()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-18 19:30')
        ->and($this->s->venueA->fresh()->default_event_settings)->toMatchArray(['price_type' => 'ticket', 'start_hour' => 21, 'start_minute' => 30]);

    $this->s->venueA->update(['auto_publish' => false]);
    $this->postJson($this->venueUrl.'/events', [
        'title' => 'Serata in attesa', 'publish' => true, 'category_id' => $this->s->category->id, 'price_type' => 'free',
        'starts_at' => '2026-09-19T21:00:00+02:00',
    ])->assertCreated()->assertJsonPath('meta.outcome', 'pending');

    // «Pubblica» chiede data e categoria.
    $this->postJson($this->venueUrl.'/events', ['title' => 'Senza data', 'publish' => true])->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_at', 'category_id', 'price_type'], 'error.fields');
});

it('crea la ripetizione settimanale insieme alla prima data', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $response = $this->postJson($this->venueUrl.'/events', [
        'title' => 'Ogni giovedì', 'publish' => true, 'category_id' => $this->s->category->id, 'price_type' => 'free',
        'starts_at' => '2026-09-03T21:00:00+02:00',
        'repeat' => ['frequency' => 'weekly', 'weekdays' => ['thu'], 'until' => '2026-09-24'],
    ])->assertCreated();

    expect(EventOccurrence::query()->where('event_id', $response->json('data.id'))->count())->toBe(4);
});

it('rimanda in revisione testo e voci di listino ma non prezzo e categoria, come il pannello', function (): void {
    $this->s->venueA->update(['auto_publish' => false]);
    Sanctum::actingAs($this->s->editorA);
    $url = $this->venueUrl.'/events/'.$this->s->publishedEventA->id;

    $this->patchJson($url, ['price_type' => 'ticket', 'price_min' => 8])->assertOk()
        ->assertJsonPath('meta.sent_to_review', false)->assertJsonPath('data.status.value', 'published');

    $this->patchJson($url, ['title' => 'Concerto di musica popolare'])->assertOk()
        ->assertJsonPath('meta.sent_to_review', false);

    $this->patchJson($url, ['ticket_tiers' => [['name' => 'Platea', 'price' => 10, 'status' => 'available']]])->assertOk()
        ->assertJsonPath('meta.sent_to_review', true)->assertJsonPath('data.status.value', 'pending')
        ->assertJsonPath('data.ticket_tiers.0.name', 'Platea');

    $this->s->publishedEventA->refresh()->forceFill(['status' => EventStatus::Published])->saveQuietly();
    $tier = TicketTier::query()->where('event_id', $this->s->publishedEventA->id)->firstOrFail();
    // Cambiare solo prezzo e disponibilità di una voce non è contenuto nuovo.
    $this->patchJson($url, ['ticket_tiers' => [['id' => $tier->id, 'name' => 'Platea', 'price' => 15, 'status' => TicketTierStatus::SoldOut->value]]])->assertOk()
        ->assertJsonPath('meta.sent_to_review', false);
    expect($tier->fresh()->status)->toBe(TicketTierStatus::SoldOut);

    $this->patchJson($url, ['description' => '<p>Testo nuovo</p>'])->assertOk()
        ->assertJsonPath('meta.sent_to_review', true);
});

it('lascia online le modifiche del locale che pubblica da sé', function (): void {
    $this->s->venueA->update(['auto_publish' => true]);
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id, [
        'title' => 'Titolo nuovo',
        'facts' => [['label' => 'Apertura porte', 'value' => '20:30']],
        'seo' => ['title' => 'Concerto a Padova'],
        'external_links' => [['label' => 'Sito della band', 'url' => 'https://example.org']],
    ])->assertOk()
        ->assertJsonPath('meta.sent_to_review', false)
        ->assertJsonPath('data.status.value', 'published')
        ->assertJsonPath('data.facts.0.value', '20:30')
        ->assertJsonPath('data.seo.title', 'Concerto a Padova');
});

it('valida i campi della modifica con gli errori sotto il campo', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->venueUrl.'/events/'.$this->s->draftEventA->id, [
        'title' => '',
        'ticket_url' => 'non è un link',
        'ticket_tiers' => array_fill(0, 13, ['name' => 'x', 'status' => 'available']),
    ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'ticket_url', 'ticket_tiers'], 'error.fields');
});

it('pubblica, duplica, programma ed elimina dalla scheda del locale', function (): void {
    $this->s->venueA->update(['auto_publish' => true]);
    Sanctum::actingAs($this->s->ownerA);
    $draft = $this->s->draftEventA;

    $this->postJson($this->venueUrl.'/events/'.$draft->id.'/publish')->assertUnprocessable();
    EventOccurrence::create(['event_id' => $draft->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 19:00:00', 'UTC')]);

    $this->putJson($this->venueUrl.'/events/'.$draft->id.'/schedule', ['publish_at' => '2026-09-10T18:00:00+02:00'])->assertOk()
        ->assertJsonPath('data.scheduled_publish_at', '2026-09-10T16:00:00+00:00');
    $this->deleteJson($this->venueUrl.'/events/'.$draft->id.'/schedule')->assertOk()
        ->assertJsonPath('data.scheduled_publish_at', null);

    $this->postJson($this->venueUrl.'/events/'.$draft->id.'/publish')->assertOk()
        ->assertJsonPath('meta.outcome', 'published');

    $copy = $this->postJson($this->venueUrl.'/events/'.$draft->id.'/duplicate')->assertCreated()
        ->assertJsonPath('data.status.value', 'draft');
    expect(EventOccurrence::query()->where('event_id', $copy->json('data.id'))->count())->toBe(0);

    $this->deleteJson($this->venueUrl.'/events/'.$copy->json('data.id'))->assertNoContent();
    expect(Event::query()->find($copy->json('data.id')))->toBeNull();
});

it('non dà all’organizzazione le azioni del solo locale', function (): void {
    $organizer = managedOrganizer($this->s);
    $member = User::factory()->create();
    $organizer->users()->attach($member);
    $this->s->publishedEventB->forceFill(['organizer_id' => $organizer->id])->saveQuietly();
    Sanctum::actingAs($member);
    $base = '/api/v1/management/organizers/'.$organizer->id.'/events/'.$this->s->publishedEventB->id;

    foreach (['publish', 'duplicate'] as $action) {
        $this->postJson($base.'/'.$action)->assertNotFound();
    }
    $this->deleteJson($base)->assertStatus(405);
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/tags', ['name' => 'x'])->assertNotFound();
});

it('crea l’evento dell’organizzazione sempre in bozza, con la prima data nell’ora della città, e lo invia alla redazione', function (): void {
    $organizer = managedOrganizer($this->s);
    $member = User::factory()->create();
    $organizer->users()->attach($member);
    Sanctum::actingAs($member);
    $base = '/api/v1/management/organizers/'.$organizer->id.'/events';

    $this->postJson($base, ['title' => 'Senza tutto'])->assertUnprocessable()
        ->assertJsonValidationErrors(['description', 'city_id', 'venue_id', 'category_id', 'price_type'], 'error.fields');

    $created = $this->postJson($base, [
        'title' => 'Rassegna sotto i portici',
        'description' => '<p>Tre sere di musica.</p>',
        'city_id' => $this->s->city->id,
        'venue_id' => $this->s->venueA->id,
        'category_id' => $this->s->category->id,
        'price_type' => 'free',
        'starts_at' => '2026-09-25T20:00:00+02:00',
    ])->assertCreated()
        ->assertJsonPath('data.status.value', 'draft')
        ->assertJsonPath('data.organizer.id', $organizer->id)
        ->assertJsonPath('data.abilities.submit', true)
        ->assertJsonPath('data.abilities.publish', false);
    $event = Event::query()->findOrFail($created->json('data.id'));
    expect($event->occurrences()->first()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-25 18:00')
        ->and($event->source->value)->toBe('manual');

    $this->postJson($base.'/'.$event->id.'/submit')->assertOk()->assertJsonPath('data.status.value', 'pending');

    // Ogni modifica di un evento pubblicato torna alla redazione.
    $event->forceFill(['status' => EventStatus::Published])->saveQuietly();
    $this->patchJson($base.'/'.$event->id, ['price_type' => 'donation'])->assertOk()
        ->assertJsonPath('meta.sent_to_review', true)
        ->assertJsonPath('data.status.value', 'pending');
});

it('non invia alla redazione un evento senza date', function (): void {
    $organizer = managedOrganizer($this->s);
    $owner = User::query()->findOrFail($organizer->owner_id);
    $event = Event::create(['city_id' => $this->s->city->id, 'venue_id' => $this->s->venueA->id, 'organizer_id' => $organizer->id, 'category_id' => $this->s->category->id,
        'title' => 'Senza date', 'description' => 'x', 'status' => EventStatus::Draft, 'source' => 'manual', 'created_by' => $owner->id]);
    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/submit')->assertUnprocessable();
    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('dà il modulo con abitudini, scorciatoie nell’ora della città e categorie', function (): void {
    $this->s->venueA->update(['default_event_settings' => ['category_id' => $this->s->category->id, 'price_type' => 'ticket', 'price_min' => 10, 'start_hour' => 20, 'start_minute' => 45]]);
    Sanctum::actingAs($this->s->editorA);

    $form = $this->getJson($this->venueUrl.'/events/form')->assertOk()
        ->assertJsonPath('data.defaults.category_id', $this->s->category->id)
        ->assertJsonPath('data.defaults.price_type', 'ticket')
        ->assertJsonPath('data.start_time', '20:45')
        ->assertJsonPath('data.shortcuts.0.value', 'tonight')
        ->assertJsonPath('data.shortcuts.0.starts_at', '2026-09-01T20:45:00+02:00')
        ->assertJsonPath('data.shortcuts.3.repeats', true);
    expect($form->json('data.categories.0.id'))->toBe($this->s->category->id);
});

it('segnala gli eventi della stessa sera in zona e la chiusura straordinaria', function (): void {
    $this->s->venueA->update(['lat' => 45.4064, 'lng' => 11.8768, 'closures' => [['from' => '2026-09-12', 'to' => '2026-09-12', 'note' => 'Lavori']]]);
    $this->s->venueB->update(['lat' => 45.4070, 'lng' => 11.8770]);
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->venueUrl.'/events/checks', ['starts_at' => '2026-09-12T21:00:00+02:00'])->assertOk()
        ->assertJsonPath('data.same_night.0.title', 'Reading su poesia contemporanea')
        ->assertJsonPath('data.closure.from', '2026-09-12');
});

it('importa da Facebook con gli stessi secchi del sito', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    RateLimiter::hit('facebook-import:'.$this->s->ownerA->id, 60);
    RateLimiter::hit('facebook-import:'.$this->s->ownerA->id, 60);
    RateLimiter::hit('facebook-import:'.$this->s->ownerA->id, 60);

    $this->postJson($this->venueUrl.'/events/import/facebook', ['url' => 'https://www.facebook.com/events/123/'])
        ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('riprende i dati di Facebook alla creazione solo con il gettone giusto', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $token = app(FacebookImportDrafts::class)->put($this->s->ownerA, 'venue:'.$this->s->venueA->id, ['id' => '987', 'url' => 'https://www.facebook.com/events/987/']);

    $created = $this->postJson($this->venueUrl.'/events', ['title' => 'Da Facebook', 'facebook_import' => $token])->assertCreated()
        ->assertJsonPath('meta.facebook_photo', false);
    expect(Event::query()->findOrFail($created->json('data.id'))->source_ref)->toBe('facebook:987');

    // Gettone usato: sparisce.
    expect(Cache::get('management-facebook-import:'.hash('sha256', $token)))->toBeNull();

    $other = app(FacebookImportDrafts::class)->put($this->s->editorA, 'venue:'.$this->s->venueA->id, ['id' => '555', 'url' => 'x']);
    $second = $this->postJson($this->venueUrl.'/events', ['title' => 'Gettone altrui', 'facebook_import' => $other])->assertCreated();
    expect(Event::query()->findOrFail($second->json('data.id'))->source_ref)->toBeNull();
});

it('crea un tag condiviso dal modulo del locale e lo trova', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->postJson($this->venueUrl.'/tags', ['name' => 'Musica d’autore'])->assertCreated()->assertJsonPath('data.name', 'Musica d’autore');
    $this->getJson($this->venueUrl.'/events/lookup/tags?q=autore')->assertOk()->assertJsonPath('data.0.name', 'Musica d’autore');
});

it('mostra l’anteprima di una bozza nella forma della scheda pubblica', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    EventOccurrence::create(['event_id' => $this->s->draftEventA->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 19:00:00', 'UTC')]);

    $this->getJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/preview')->assertOk()
        ->assertJsonPath('data.title', 'Bozza del circolo Aurora');

    Sanctum::actingAs($this->s->plainUser);
    $this->getJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/preview')->assertNotFound();
});

it('nega le azioni al personale d’ingresso senza gestione', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::User->value);
    Sanctum::actingAs($staff);

    $this->getJson($this->venueUrl.'/events')->assertNotFound();
    expect(Venue::query()->whereKey($this->s->venueA->id)->exists())->toBeTrue()
        ->and(VenueRole::Editor->value)->toBe('editor');
});
