<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R2 — gli eventi della redazione nell'app: elenco, scheda, decisioni,
 * cronologia. Ogni prova passa dalla griglia dei ruoli (moderatore, admin,
 * super admin, referente di un locale, utente comune, token non sbloccato,
 * password non recente sulle azioni pericolose) e confronta il risultato
 * con quello che il pannello `/admin/events` fa sugli stessi dati.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->staff = [$this->s->moderator, $this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
});

/** Una data futura per un evento, così l'annullamento e la pubblicazione hanno qualcosa da toccare. */
function futureDate(Event $event, int $days = 3): EventOccurrence
{
    return EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(19, 0),
        'ends_at' => null,
        'doors_at' => null,
    ]);
}

it('apre elenco, scelte dei filtri, scheda e anteprima alla sola redazione sbloccata', function (): void {
    $urls = [
        '/api/v1/editorial/events',
        '/api/v1/editorial/events/filters',
        '/api/v1/editorial/events/'.$this->s->draftEventA->id,
        '/api/v1/editorial/events/'.$this->s->draftEventA->id.'/preview',
    ];

    foreach ($urls as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
    foreach ($urls as $url) {
        foreach ($this->outsiders as $outsider) {
            EditorialSession::as($this, EditorialSession::token($outsider))->getJson($url)->assertNotFound();
        }
        EditorialSession::as($this, EditorialSession::token($this->s->moderator, unlocked: false))->getJson($url)
            ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
        EditorialSession::as($this, EditorialSession::token($this->s->admin, unlockedHoursAgo: 12))->getJson($url)
            ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
        foreach ($this->staff as $staff) {
            EditorialSession::as($this, EditorialSession::token($staff))->getJson($url)->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        }
    }
});

it('dà le righe con le schede, i conteggi e le azioni che il ruolo permette', function (): void {
    $pending = Event::factory()->create(['city_id' => $this->s->city->id, 'venue_id' => $this->s->venueA->id, 'category_id' => $this->s->category->id, 'status' => EventStatus::Pending, 'title' => 'In attesa della redazione']);

    $moderator = EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->getJson('/api/v1/editorial/events?tab=pending')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.tabs.all', 5)
        ->assertJsonPath('meta.tabs.pending', 1)
        ->assertJsonPath('meta.tabs.published', 2)
        ->assertJsonPath('meta.tabs.draft', 2)
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.venue.name', 'Circolo Aurora')
        ->json('data.0.abilities');

    // Il moderatore pubblica e rifiuta, ma non mette in evidenza, non duplica, non unisce e non elimina (come il pannello).
    expect($moderator)->toMatchArray(['publish' => true, 'reject' => true, 'cancel' => true, 'feature' => false, 'duplicate' => false, 'merge' => false, 'destroy' => false, 'unpublish' => false]);

    foreach ([$this->s->admin, $this->superAdmin] as $admin) {
        $abilities = EditorialSession::as($this, EditorialSession::token($admin))
            ->getJson('/api/v1/editorial/events?tab=pending')->assertOk()->json('data.0.abilities');
        expect($abilities)->toMatchArray(['publish' => true, 'reject' => true, 'feature' => true, 'duplicate' => true, 'merge' => true, 'destroy' => true]);
    }
});

it('trova gli stessi eventi del pannello con ogni scheda, filtro e ricerca', function (array $query, string $tab, array $filters, ?string $search): void {
    $other = City::factory()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);
    Event::factory()->create(['city_id' => $other->id, 'status' => EventStatus::Cancelled, 'title' => 'Annullato altrove', 'is_featured' => true]);
    Event::factory()->create(['city_id' => $this->s->city->id, 'venue_id' => $this->s->venueB->id, 'status' => EventStatus::Pending, 'title' => 'Proposta per il Belzoni']);
    $trashed = Event::factory()->create(['city_id' => $this->s->city->id, 'status' => EventStatus::Draft, 'title' => 'Bozza cestinata']);
    $trashed->delete();
    $this->s->publishedEventA->update(['published_at' => now()->subHour()]);

    // I segnaposto dei dati di prova diventano gli id veri, anche dentro `filter[…]`.
    $ids = [':venueB' => $this->s->venueB->id, ':other' => $other->id];
    array_walk_recursive($query, function (mixed &$value) use ($ids): void {
        $value = is_string($value) && isset($ids[$value]) ? (string) $ids[$value] : $value;
    });
    array_walk_recursive($filters, function (mixed &$value) use ($ids): void {
        $value = is_string($value) && isset($ids[$value]) ? $ids[$value] : $value;
    });

    $api = EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->getJson('/api/v1/editorial/events?'.http_build_query([...$query, 'per_page' => 50, 'city' => $this->s->city->slug]))
        ->assertOk()->json('data.*.id');

    // Il pannello con la sessione del sito: senza token e con la guardia `web`.
    $this->app['auth']->forgetGuards();
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events')->assertOk();
    $panel = Livewire::test(ListEvents::class)->set('activeTab', $tab);
    foreach ($filters as $name => $value) {
        $panel->filterTable($name, $value);
    }
    if ($search !== null) {
        $panel->searchTable($search);
    }
    $expected = $panel->instance()->getTableRecords()->pluck('id')->all();

    expect($api)->toEqualCanonicalizing($expected)->and($api)->not->toBe([]);
})->with([
    'tutti' => [[], 'all', [], null],
    'in attesa' => [['tab' => 'pending'], 'pending', [], null],
    'annullati' => [['tab' => 'cancelled'], 'cancelled', [], null],
    'stato' => [['filter' => ['status' => 'draft']], 'all', ['status' => 'draft'], null],
    'città' => [['filter' => ['city' => ':other']], 'all', ['city' => ':other'], null],
    'locale' => [['filter' => ['venue' => ':venueB']], 'all', ['venue' => ':venueB'], null],
    'in evidenza' => [['filter' => ['is_featured' => '1']], 'all', ['is_featured' => true], null],
    'pubblicati oggi' => [['filter' => ['published_today' => '1']], 'all', ['published_today' => true], null],
    'senza locandina' => [['filter' => ['missing_poster' => '1']], 'all', ['missing_poster' => true], null],
    'cestinati' => [['filter' => ['trashed' => 'only']], 'all', ['trashed' => false], null],
    'ricerca nel titolo' => [['q' => 'Bozza'], 'all', [], 'Bozza'],
    'ricerca nel locale' => [['q' => 'Belzoni'], 'all', [], 'Belzoni'],
]);

it('mostra la scheda con il riquadro della redazione, i collegamenti e quanto tocca un annullamento', function (): void {
    $date = futureDate($this->s->publishedEventA);
    $this->s->plainUser->savedOccurrences()->attach($date->id);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->getJson('/api/v1/editorial/events/'.$this->s->publishedEventA->id)->assertOk()
        ->assertJsonPath('data.title', 'Concerto di musica popolare')
        ->assertJsonPath('data.city.timezone', 'Europe/Rome')
        ->assertJsonPath('data.editorial.source', $this->s->publishedEventA->source->label())
        ->assertJsonPath('data.cancel_impact.upcoming_dates', 1)
        ->assertJsonPath('data.cancel_impact.saved_by', 1)
        ->assertJsonPath('data.abilities.unpublish', true)
        ->assertJsonPath('data.abilities.activity', false)
        ->assertJsonPath('data.links.statistics', null)
        ->assertJsonPath('data.links.sponsor', null)
        ->assertJsonPath('data.dates.upcoming.0.id', $date->id);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->getJson('/api/v1/editorial/events/'.$this->s->publishedEventA->id)->assertOk()
        ->assertJsonPath('data.abilities.activity', true)
        ->assertJsonPath('data.abilities.sponsor', true)
        ->assertJsonPath('data.links.statistics', 'admin/statistiche-condivisioni/event/'.$this->s->publishedEventA->id)
        ->assertJsonPath('data.links.sponsor', 'admin/sponsorships/create?event='.$this->s->publishedEventA->id);
});

it('fa pubblicare e rifiutare al moderatore come il pannello, e gli nega evidenza, copia e unione', function (): void {
    futureDate($this->s->draftEventA);
    $moderator = EditorialSession::token($this->s->moderator);
    $base = '/api/v1/editorial/events/'.$this->s->draftEventA->id;

    foreach ($this->outsiders as $outsider) {
        EditorialSession::as($this, EditorialSession::token($outsider))->postJson($base.'/publish')->assertNotFound();
    }
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, unlocked: false))->postJson($base.'/publish')
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    EditorialSession::as($this, $moderator)->postJson($base.'/publish')->assertOk()
        ->assertJsonPath('data.status.value', 'published')
        ->assertJsonPath('message', __('admin.notifications.published'));
    EditorialSession::as($this, $moderator)->postJson($base.'/publish')->assertStatus(409);
    EditorialSession::as($this, $moderator)->postJson($base.'/unpublish')->assertOk()->assertJsonPath('data.status.value', 'draft');
    EditorialSession::as($this, $moderator)->postJson($base.'/reject', ['reason' => ''])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    EditorialSession::as($this, $moderator)->postJson($base.'/reject', ['reason' => 'Mancano gli orari'])->assertOk()
        ->assertJsonPath('data.status.value', 'rejected');
    expect($this->s->draftEventA->fresh()->rejection_reason)->toBe('Mancano gli orari');

    EditorialSession::as($this, $moderator)->postJson($base.'/feature', ['featured' => true])->assertForbidden();
    EditorialSession::as($this, $moderator)->postJson($base.'/duplicate')->assertForbidden();
    EditorialSession::as($this, $moderator)->getJson($base.'/merge-candidates')->assertForbidden();
    EditorialSession::as($this, $moderator)->getJson($base.'/activity')->assertForbidden();

    // Ogni decisione presa dall'app va nel registro con `canale: app`.
    $last = Activity::query()->where('subject_type', $this->s->draftEventA->getMorphClass())->where('subject_id', $this->s->draftEventA->id)->latest('id')->firstOrFail();
    expect($last->getProperty('canale'))->toBe('app')->and($last->causer_id)->toBe($this->s->moderator->id);
});

it('non pubblica un evento senza date', function (): void {
    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->postJson('/api/v1/editorial/events/'.$this->s->draftEventA->id.'/publish')
        ->assertUnprocessable()->assertJsonPath('error.fields.event.0', __('admin.notifications.cannot_publish_without_date'));
    expect($this->s->draftEventA->fresh()->status)->toBe(EventStatus::Draft);
});

it('annulla l’evento e le sue date future solo con la password recente', function (): void {
    $date = futureDate($this->s->publishedEventA);
    $url = '/api/v1/editorial/events/'.$this->s->publishedEventA->id.'/cancel';

    EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 16))->postJson($url, ['note' => 'Maltempo'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect($this->s->publishedEventA->fresh()->status)->toBe(EventStatus::Published);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['note' => 'Maltempo'])->assertOk()
        ->assertJsonPath('data.status.value', 'cancelled');
    expect($date->fresh()->status)->toBe(OccurrenceStatus::Cancelled)->and($date->fresh()->status_note)->toBe('Maltempo')
        // La data passata dello scenario resta com'era.
        ->and($this->s->occurrenceA->fresh()->status)->not->toBe(OccurrenceStatus::Cancelled);
});

it('mette in evidenza con la scadenza, duplica e ripristina per admin e super admin', function (): void {
    foreach ([$this->s->admin, $this->superAdmin] as $admin) {
        $token = EditorialSession::token($admin);
        $base = '/api/v1/editorial/events/'.$this->s->publishedEventA->id;

        EditorialSession::as($this, $token)->postJson($base.'/feature', ['featured' => true, 'until' => '2026-12-24T23:00:00+01:00'])->assertOk()
            ->assertJsonPath('data.is_featured', true)->assertJsonPath('data.featured_until', '2026-12-24T22:00:00+00:00');
        EditorialSession::as($this, $token)->postJson($base.'/feature', ['featured' => false])->assertOk()
            ->assertJsonPath('data.is_featured', false)->assertJsonPath('data.featured_until', null);

        $copy = EditorialSession::as($this, $token)->postJson($base.'/duplicate')->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')->json('data.id');
        expect(Event::query()->find($copy)->created_by)->toBe($admin->id);
    }
});

it('unisce solo un doppione della stessa città, con la password recente', function (): void {
    $keep = $this->s->publishedEventA;
    $twin = Event::factory()->create(['city_id' => $this->s->city->id, 'venue_id' => $this->s->venueA->id, 'title' => 'Concerto di musica popolare (doppione)']);
    $elsewhere = Event::factory()->create(['city_id' => City::factory()->create()->id, 'title' => 'Concerto di musica popolare altrove']);
    $url = '/api/v1/editorial/events/'.$keep->id.'/merge';

    $candidates = EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->getJson('/api/v1/editorial/events/'.$keep->id.'/merge-candidates?q=popolare')->assertOk()->json('data.*.id');
    expect($candidates)->toBe([$twin->id]);

    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->postJson($url, ['absorbed' => $twin->id])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['absorbed' => $twin->id])->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['absorbed' => $elsewhere->id])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['absorbed' => $keep->id])->assertUnprocessable();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['absorbed' => $twin->id])->assertOk()
        ->assertJsonPath('data.id', $keep->id)
        ->assertJsonPath('message', __('admin.events.merged', ['title' => $twin->title]));
    expect($twin->fresh()->trashed())->toBeTrue()->and($elsewhere->fresh()->trashed())->toBeFalse();
});

it('elimina nel cestino con la password recente e ripristina', function (): void {
    $url = '/api/v1/editorial/events/'.$this->s->draftEventB->id;

    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->deleteJson($url)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->deleteJson($url)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->deleteJson($url)->assertOk()->assertJsonPath('data.trashed', true);
    expect($this->s->draftEventB->fresh()->trashed())->toBeTrue();

    // Cestinato: si apre ancora, ma le decisioni non valgono più; si ripristina.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($url)->assertOk()
        ->assertJsonPath('data.trashed', true)->assertJsonPath('data.abilities.restore', true)->assertJsonPath('data.abilities.publish', false);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url.'/publish')->assertNotFound();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url.'/restore')->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url.'/restore')->assertOk()->assertJsonPath('data.trashed', false);
});

it('elimina in gruppo solo le righe permesse, con la password recente', function (): void {
    $ids = [$this->s->draftEventA->id, $this->s->draftEventB->id];

    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->postJson('/api/v1/editorial/events/bulk-delete', ['ids' => $ids])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson('/api/v1/editorial/events/bulk-delete', ['ids' => $ids])->assertOk()
        ->assertJsonPath('data.deleted', [])->assertJsonPath('data.skipped', 2);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/events/bulk-delete', ['ids' => $ids])->assertOk()
        ->assertJsonPath('data.skipped', 0);
    expect(Event::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

it('legge la cronologia e ripristina i valori di prima solo per chi modifica l’evento', function (): void {
    $this->actingAs($this->s->admin, 'web');
    $this->s->draftEventA->update(['title' => 'Titolo sbagliato', 'status' => EventStatus::Pending]);
    $this->app['auth']->forgetGuards();
    $row = Activity::query()->where('subject_id', $this->s->draftEventA->id)->where('event', 'updated')->latest('id')->firstOrFail();
    $url = '/api/v1/editorial/events/'.$this->s->draftEventA->id.'/activity';

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson($url)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url.'/'.$row->id.'/restore')->assertForbidden();

    $data = EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($url)->assertOk()->json('data');
    $updated = collect($data)->firstWhere('id', $row->id);
    expect($updated['abilities']['restore'])->toBeTrue()
        ->and($updated['changes'])->toContain('Titolo sbagliato')
        ->and($updated['restorable_fields'])->toBe([__('admin.fields.title')]);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url.'/'.$row->id.'/restore')->assertOk()
        ->assertJsonPath('data.title', 'Bozza del circolo Aurora')
        ->assertJsonPath('message', __('admin.activity.restored'));
    // Lo stato non si riavvolge dalla cronologia: passa dalla moderazione.
    expect($this->s->draftEventA->fresh()->status)->toBe(EventStatus::Pending);

    $foreign = Activity::query()->where('subject_id', '!=', $this->s->draftEventA->id)->value('id');
    if ($foreign !== null) {
        EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url.'/'.$foreign.'/restore')->assertNotFound();
    }
});

it('dà l’anteprima della pagina anche di una bozza, nella forma della scheda pubblica', function (): void {
    futureDate($this->s->draftEventA);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->getJson('/api/v1/editorial/events/'.$this->s->draftEventA->id.'/preview')->assertOk()
        ->assertJsonPath('data.title', 'Bozza del circolo Aurora')
        ->assertJsonStructure(['data' => ['id', 'slug', 'title', 'occurrences']]);
});

it('esporta in Excel le righe che si stanno guardando', function (): void {
    foreach ($this->outsiders as $outsider) {
        EditorialSession::as($this, EditorialSession::token($outsider))->get('/api/v1/editorial/events/export')->assertNotFound();
    }

    $response = EditorialSession::as($this, EditorialSession::token($this->s->moderator))->get('/api/v1/editorial/events/export?tab=draft');
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('incitta-eventi-');
});
