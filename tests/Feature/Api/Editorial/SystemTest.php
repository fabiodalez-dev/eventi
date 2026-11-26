<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Jobs\Import\ImportSourceJob;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EditorialSession;
use Tests\Support\IcsFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * R12 — Sistema nell'app della redazione (R-SY-01…07): sorgenti di import,
 * invii previsti, cron e programmazioni, con gli stessi permessi, le stesse
 * regole e gli stessi limiti del gruppo «Sistema» del pannello. La griglia:
 * moderatore, admin, super admin, referente di un locale, utente comune,
 * token non sbloccato (423), password non recente sull'eliminazione (423).
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->admins = [$this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
    IcsFixtures::fake('internal-entries');
    $this->source = ImportSource::factory()->create([
        'city_id' => $this->s->city->id,
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->s->category->id,
        'credentials' => 'utente:segreto',
        'mapping' => ['timezone' => 'Europe/Rome', 'exclude_keywords' => ['riunione', 'staff']],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Lo stesso controllo per ogni indirizzo: fuori la gente comune (404), chiuso senza sblocco (423). */
function r12Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->moderator, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

function r12Client(mixed $test, User $user, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as($test, EditorialSession::token($user, passwordMinutesAgo: $passwordMinutesAgo));
}

// ------------------------------------------------------------------ import

it('elenca le sorgenti ad admin e super admin con le colonne del pannello, senza mai le credenziali', function (): void {
    r12Gate($this, 'GET', '/api/v1/editorial/import-sources');
    r12Client($this, $this->s->moderator)->getJson('/api/v1/editorial/import-sources')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

    foreach ($this->admins as $person) {
        $response = r12Client($this, $person)->getJson('/api/v1/editorial/import-sources')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 24);
        $row = $response->json('data.0');

        expect($row['id'])->toBe($this->source->id)
            ->and($row['city'])->toBe(['id' => $this->s->city->id, 'name' => $this->s->city->name])
            ->and($row['venue'])->toBeNull()
            ->and($row['type'])->toBe('ics')
            ->and($row['type_label'])->toBe(ImportSourceType::Ics->label())
            ->and($row['url'])->toBe(IcsFixtures::URL)
            ->and($row['has_credentials'])->toBeTrue()
            ->and($row['mapping'])->toBe(['timezone' => 'Europe/Rome', 'exclude_keywords' => ['riunione', 'staff']])
            ->and($row['default_category'])->toBe(['id' => $this->s->category->id, 'name' => $this->s->category->name])
            ->and($row['is_active'])->toBeTrue()
            ->and($row['runnable'])->toBeTrue()
            ->and($row['web_path'])->toBe('admin/import-sources/'.$this->source->id.'/edit')
            ->and($row['abilities'])->toBe(['update' => true, 'destroy' => true, 'preview' => true, 'run' => true, 'runs' => true])
            ->and($response->getContent())->not->toContain('segreto')
            ->and($row)->not->toHaveKey('credentials');
    }
});

it('filtra per tipo, attiva e «import falliti», ordina per ultima esecuzione e tronca le righe per pagina', function (): void {
    $failed = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'last_status' => ImportRunStatus::Failed->value, 'last_error' => 'Calendario irraggiungibile', 'last_run_at' => now()->subHour()]);
    $paused = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'is_active' => false, 'last_status' => ImportRunStatus::Failed->value, 'last_run_at' => now()->subDays(2)]);
    $manual = ImportSource::factory()->ofType(ImportSourceType::Manual)->create(['city_id' => $this->s->city->id, 'url' => null, 'last_run_at' => now()->subDay()]);
    $client = r12Client($this, $this->s->admin);

    expect($client->getJson('/api/v1/editorial/import-sources')->json('data.*.id'))->toBe([$failed->id, $manual->id, $paused->id, $this->source->id])
        ->and($client->getJson('/api/v1/editorial/import-sources?sort=last_run_at')->json('data.*.id'))->toBe([$this->source->id, $paused->id, $manual->id, $failed->id])
        ->and($client->getJson('/api/v1/editorial/import-sources?filter[type]=manual')->json('data.*.id'))->toBe([$manual->id])
        ->and($client->getJson('/api/v1/editorial/import-sources?filter[active]=0')->json('data.*.id'))->toBe([$paused->id])
        ->and($client->getJson('/api/v1/editorial/import-sources?filter[active]=1')->json('data.*.id'))->toBe([$failed->id, $manual->id, $this->source->id])
        // «Import falliti»: attive e ferme su un errore, come il riquadro del riepilogo.
        ->and($client->getJson('/api/v1/editorial/import-sources?filter[failed]=1')->json('data.*.id'))->toBe([$failed->id])
        ->and($client->getJson('/api/v1/editorial/import-sources?per_page=1&page=2')->json('data.*.id'))->toBe([$manual->id]);

    $row = collect($client->getJson('/api/v1/editorial/import-sources')->json('data'))->firstWhere('id', $manual->id);
    // Senza un lettore niente anteprima né esecuzione, come i pulsanti del pannello.
    expect($row['runnable'])->toBeFalse()
        ->and($row['abilities']['preview'])->toBeFalse()
        ->and($row['abilities']['run'])->toBeFalse()
        ->and($row['has_credentials'])->toBeFalse();

    $client->getJson('/api/v1/editorial/import-sources?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    $client->getJson('/api/v1/editorial/import-sources?filter[type]=xml')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $client->getJson('/api/v1/editorial/import-sources?sort=url')->assertUnprocessable();
});

it('dà le scelte del modulo e cerca i locali per nome', function (): void {
    r12Gate($this, 'GET', '/api/v1/editorial/import-sources/form');
    r12Client($this, $this->s->moderator)->getJson('/api/v1/editorial/import-sources/form')->assertForbidden();
    r12Client($this, $this->s->moderator)->getJson('/api/v1/editorial/import-sources/venues')->assertForbidden();

    $form = r12Client($this, $this->s->admin)->getJson('/api/v1/editorial/import-sources/form')->assertOk()->json('data');
    expect(collect($form['types'])->pluck('value')->all())->toBe(ImportSourceType::values())
        ->and(collect($form['types'])->where('runnable', true)->pluck('value')->all())->toBe(['ics', 'csv'])
        ->and(collect($form['cities'])->pluck('id')->all())->toContain($this->s->city->id)
        ->and(collect($form['categories'])->pluck('id')->all())->toContain($this->s->category->id)
        ->and($form['defaults'])->toBe(['city_id' => $this->s->city->id, 'type' => 'ics', 'is_active' => true])
        ->and($form['limits']['max_url_length'])->toBe(1000);

    $venues = r12Client($this, $this->s->admin)->getJson('/api/v1/editorial/import-sources/venues?q='.urlencode(mb_substr($this->s->venueA->name, 0, 6)))->assertOk()->json('data');
    expect(collect($venues)->pluck('id')->all())->toContain($this->s->venueA->id)
        ->and($venues[0])->toHaveKeys(['id', 'name', 'city']);
});

it('crea una sorgente con le regole del modulo del pannello', function (): void {
    $body = [
        'city_id' => $this->s->city->id,
        'type' => 'ics',
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->s->category->id,
        'mapping' => ['timezone' => 'Europe/Rome'],
    ];
    r12Gate($this, 'POST', '/api/v1/editorial/import-sources', $body);
    r12Client($this, $this->s->moderator)->postJson('/api/v1/editorial/import-sources', $body)->assertForbidden();

    $created = r12Client($this, $this->s->admin)->postJson('/api/v1/editorial/import-sources', $body)->assertCreated()
        ->assertJsonPath('meta.message', __('admin.import.saved'))
        ->json('data');
    $saved = ImportSource::query()->findOrFail($created['id']);
    // Attiva se non si dice altro, come il pannello; nessuna credenziale.
    expect($saved->is_active)->toBeTrue()
        ->and($saved->mapping)->toBe(['timezone' => 'Europe/Rome'])
        ->and($saved->getRawOriginal('credentials'))->toBeNull()
        ->and($created['has_credentials'])->toBeFalse();

    $client = r12Client($this, $this->superAdmin);
    // Senza locale la categoria predefinita è obbligatoria; con un locale no.
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'default_category_id' => null])->assertUnprocessable()
        ->assertJsonValidationErrors('default_category_id', 'error.fields');
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'default_category_id' => null, 'venue_id' => $this->s->venueA->id])->assertCreated()
        ->assertJsonPath('data.venue.id', $this->s->venueA->id);
    // Città e tipo obbligatori, tipo dell'elenco, indirizzo verificato come nel modulo (rete interna rifiutata).
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'city_id' => null, 'type' => 'xml'])->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id', 'type'], 'error.fields');
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'url' => 'http://127.0.0.1/calendario.ics'])->assertUnprocessable()
        ->assertJsonValidationErrors('url', 'error.fields');
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'url' => 'https://calendario.test/'.str_repeat('a', 1000)])->assertUnprocessable()
        ->assertJsonValidationErrors('url', 'error.fields');
    // Le credenziali dall'app no (decisione 6): si scrivono dal sito.
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'credentials' => 'utente:password'])->assertUnprocessable()
        ->assertJsonValidationErrors('credentials', 'error.fields');
    // Una mappatura che non è un dizionario di testi.
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'mapping' => ['solo', 'valori']])->assertUnprocessable()
        ->assertJsonValidationErrors('mapping', 'error.fields');
    $client->postJson('/api/v1/editorial/import-sources', [...$body, 'mapping' => ['annidata' => ['a' => ['b' => 'c']]]])->assertUnprocessable()
        ->assertJsonValidationErrors('mapping', 'error.fields');
});

it('modifica una sorgente anche solo per attivarla o disattivarla, e non tocca le credenziali salvate dal sito', function (): void {
    $url = '/api/v1/editorial/import-sources/'.$this->source->id;
    r12Gate($this, 'PATCH', $url, ['is_active' => false]);
    r12Client($this, $this->s->moderator)->patchJson($url, ['is_active' => false])->assertForbidden();

    r12Client($this, $this->s->admin)->patchJson($url, ['is_active' => false])->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.has_credentials', true);
    $fresh = $this->source->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->credentials)->toBe('utente:segreto')
        ->and($fresh->url)->toBe(IcsFixtures::URL);

    r12Client($this, $this->superAdmin)->patchJson($url, ['is_active' => true, 'mapping' => ['exclude_keywords' => ['chiuso']], 'type' => 'csv'])->assertOk()
        ->assertJsonPath('data.type', 'csv')
        ->assertJsonPath('data.mapping.exclude_keywords', ['chiuso']);
    // Togliere la categoria a una sorgente senza locale non si può; con un locale sì.
    r12Client($this, $this->s->admin)->patchJson($url, ['default_category_id' => null])->assertUnprocessable()
        ->assertJsonValidationErrors('default_category_id', 'error.fields');
    r12Client($this, $this->s->admin)->patchJson($url, ['venue_id' => $this->s->venueB->id, 'default_category_id' => null])->assertOk()
        ->assertJsonPath('data.venue.id', $this->s->venueB->id)
        ->assertJsonPath('data.default_category', null);
    r12Client($this, $this->s->admin)->patchJson($url, ['venue_id' => null])->assertUnprocessable()
        ->assertJsonValidationErrors('default_category_id', 'error.fields');
    r12Client($this, $this->s->admin)->patchJson($url, ['credentials' => 'nuove'])->assertUnprocessable();
    r12Client($this, $this->s->admin)->patchJson('/api/v1/editorial/import-sources/99999999', ['is_active' => true])->assertNotFound();
});

it('chiede la password recente per eliminare, e con la sorgente va il suo storico', function (): void {
    ImportRun::factory()->create(['import_source_id' => $this->source->id]);
    $url = '/api/v1/editorial/import-sources/'.$this->source->id;
    r12Gate($this, 'DELETE', $url);

    r12Client($this, $this->s->admin, passwordMinutesAgo: 16)->deleteJson($url)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r12Client($this, $this->s->moderator)->deleteJson($url)->assertForbidden();
    expect(ImportSource::query()->whereKey($this->source->id)->exists())->toBeTrue();

    r12Client($this, $this->s->admin)->deleteJson($url)->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('meta.message', __('admin.import.deleted'));
    expect(ImportSource::query()->whereKey($this->source->id)->exists())->toBeFalse()
        ->and(ImportRun::query()->where('import_source_id', $this->source->id)->count())->toBe(0);
    r12Client($this, $this->superAdmin)->deleteJson($url)->assertNotFound();
});

it('mostra l anteprima nell ora della città senza scrivere niente, e il motivo quando il calendario non si legge', function (): void {
    Carbon::setTestNow(localInstant($this->s->city, '2026-08-20 12:00:00'));
    $url = '/api/v1/editorial/import-sources/'.$this->source->id.'/preview';
    r12Gate($this, 'POST', $url);
    r12Client($this, $this->s->moderator)->postJson($url)->assertForbidden();

    $data = r12Client($this, $this->s->admin)->postJson($url)->assertOk()->json('data');
    $titles = collect($data['rows'])->pluck('title')->all();
    expect($data['error'])->toBeNull()
        ->and($data['timezone'])->toBe($this->s->city->timezone)
        ->and($titles)->toContain("Concerto del quartetto d'archi")
        // Le voci escluse dalla mappatura non arrivano nemmeno all'anteprima.
        ->and(implode(' ', $titles))->not->toContain('Riunione staff')
        ->and(collect($data['rows'])->pluck('when')->all())->toContain('05/09/2026 21:30')
        ->and($data['rows'][0])->toHaveKeys(['when', 'title', 'where', 'notes', 'cancelled'])
        ->and($this->source->fresh()->last_run_at)->toBeNull();

    IcsFixtures::fake('not-a-calendar');
    r12Client($this, $this->superAdmin)->postJson($url)->assertOk()
        ->assertJsonPath('data.rows', [])
        ->assertJsonPath('data.error', __('import.errors.not_a_calendar'));

    $manual = ImportSource::factory()->ofType(ImportSourceType::Manual)->create(['city_id' => $this->s->city->id, 'url' => null, 'default_category_id' => $this->s->category->id]);
    r12Client($this, $this->s->admin)->postJson('/api/v1/editorial/import-sources/'.$manual->id.'/preview')->assertUnprocessable()
        ->assertJsonPath('error.fields.type.0', __('admin.import.not_runnable'));
});

it('accoda «Esegui adesso» come il pulsante del pannello', function (): void {
    Queue::fake();
    $url = '/api/v1/editorial/import-sources/'.$this->source->id.'/run';
    r12Gate($this, 'POST', $url);
    r12Client($this, $this->s->moderator)->postJson($url)->assertForbidden();
    Queue::assertNothingPushed();

    r12Client($this, $this->s->admin)->postJson($url)->assertStatus(202)
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('meta.message', __('admin.notifications.import_queued'));
    Queue::assertPushed(ImportSourceJob::class, fn (ImportSourceJob $job): bool => $job->sourceId === $this->source->id);

    $manual = ImportSource::factory()->ofType(ImportSourceType::Manual)->create(['city_id' => $this->s->city->id, 'url' => null, 'default_category_id' => $this->s->category->id]);
    r12Client($this, $this->superAdmin)->postJson('/api/v1/editorial/import-sources/'.$manual->id.'/run')->assertUnprocessable();
    Queue::assertPushed(ImportSourceJob::class, 1);
});

it('elenca lo storico delle esecuzioni, dalla più recente, con il filtro «Esito»', function (): void {
    $old = ImportRun::factory()->create(['import_source_id' => $this->source->id, 'status' => ImportRunStatus::Success->value, 'created_count' => 4, 'created_at' => now()->subDays(2)]);
    $failed = ImportRun::factory()->create(['import_source_id' => $this->source->id, 'status' => ImportRunStatus::Failed->value, 'message' => 'Timeout', 'created_at' => now()->subDay()]);
    ImportRun::factory()->create(['import_source_id' => ImportSource::factory()->create(['city_id' => $this->s->city->id])->id]);
    $url = '/api/v1/editorial/import-sources/'.$this->source->id.'/runs';
    r12Gate($this, 'GET', $url);
    r12Client($this, $this->s->moderator)->getJson($url)->assertForbidden();

    $client = r12Client($this, $this->s->admin);
    $rows = $client->getJson($url)->assertOk()->assertJsonPath('meta.total', 2)->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$failed->id, $old->id])
        ->and($rows[0]['status_label'])->toBe(ImportRunStatus::Failed->label())
        ->and($rows[0]['message'])->toBe('Timeout')
        ->and($rows[1]['created_count'])->toBe(4)
        ->and($rows[1])->toHaveKeys(['created_at', 'updated_count', 'unchanged_count', 'excluded_count', 'cancelled_count', 'error_count'])
        ->and($client->getJson($url.'?filter[status]=success')->json('data.*.id'))->toBe([$old->id])
        ->and($client->getJson($url.'?sort=created_at')->json('data.*.id'))->toBe([$old->id, $failed->id]);
    $client->getJson($url.'?filter[status]=boh')->assertUnprocessable();
    $client->getJson('/api/v1/editorial/import-sources/99999999/runs')->assertNotFound();
});

// ------------------------------------------------------------ invii previsti

function r12Notification(array $attributes = []): ScheduledNotification
{
    return ScheduledNotification::factory()->create($attributes);
}

it('elenca gli invii previsti a tutta la redazione con le colonne del pannello; fermarli è di admin e super admin', function (): void {
    $recipient = User::factory()->create(['email' => 'giulia.ferrari@example.test']);
    $pending = r12Notification([
        'user_id' => $recipient->id, 'type' => NotificationType::EventReminder->value, 'status' => NotificationStatus::Pending,
        'channel' => NotificationChannel::Push, 'send_at' => now()->addHour(),
        'notifiable_type' => 'event_occurrence', 'notifiable_id' => $this->s->occurrenceA->id,
    ]);
    r12Gate($this, 'GET', '/api/v1/editorial/scheduled-notifications');

    foreach ([$this->s->moderator, ...$this->admins] as $person) {
        $row = r12Client($this, $person)->getJson('/api/v1/editorial/scheduled-notifications')->assertOk()
            ->assertJsonPath('meta.total', 1)->json('data.0');
        expect($row['id'])->toBe($pending->id)
            ->and($row['type'])->toBe('event_reminder')
            ->and($row['type_label'])->toBe(NotificationType::EventReminder->label())
            ->and($row['recipient'])->toBe('giulia.ferrari@example.test')
            ->and($row['subject'])->toBe(__('admin.resources.event_occurrence.label').' '.__('common.separator').' '.$this->s->publishedEventA->title)
            ->and($row['status'])->toBe('pending')
            ->and($row['status_label'])->toBe(NotificationStatus::Pending->label())
            ->and($row['channel'])->toBe('push')
            ->and($row)->toHaveKeys(['send_at', 'sent_at', 'attempts', 'reason', 'dedupe_key', 'channel_label'])
            // Il moderatore guarda e non ferma, come nel pannello (`notifications.manage`).
            ->and($row['abilities'])->toBe(['cancel' => $person->isNot($this->s->moderator)]);
    }
});

it('filtra gli invii per stato, tipo e «in scadenza», cerca l email del destinatario e ordina per orario', function (): void {
    Carbon::setTestNow('2026-09-01 10:00:00');
    $anna = User::factory()->create(['email' => 'anna@example.test']);
    $due = r12Notification(['user_id' => $anna->id, 'type' => NotificationType::DailyDigest->value, 'status' => NotificationStatus::Pending, 'send_at' => now()->subMinutes(5)]);
    $later = r12Notification(['type' => NotificationType::EventReminder->value, 'status' => NotificationStatus::Pending, 'send_at' => now()->addDay()]);
    $skipped = r12Notification(['type' => NotificationType::EventReminder->value, 'status' => NotificationStatus::Skipped, 'last_error' => NotificationSkipReason::cases()[0]->value, 'send_at' => now()->subDay()]);
    $client = r12Client($this, $this->s->moderator);

    expect($client->getJson('/api/v1/editorial/scheduled-notifications')->json('data.*.id'))->toBe([$later->id, $due->id, $skipped->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?sort=send_at')->json('data.*.id'))->toBe([$skipped->id, $due->id, $later->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?filter[status]=skipped')->json('data.*.id'))->toBe([$skipped->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?filter[type]=daily_digest')->json('data.*.id'))->toBe([$due->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?filter[due]=1')->json('data.*.id'))->toBe([$due->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?q=anna@')->json('data.*.id'))->toBe([$due->id])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?q=%25')->json('data.*.id'))->toBe([])
        ->and($client->getJson('/api/v1/editorial/scheduled-notifications?filter[status]=skipped')->json('data.0.reason'))->toBe(NotificationSkipReason::cases()[0]->label());

    $client->getJson('/api/v1/editorial/scheduled-notifications?filter[type]=boh')->assertUnprocessable();
    $client->getJson('/api/v1/editorial/scheduled-notifications?sort=user_id')->assertUnprocessable();
});

it('ferma un invio in attesa, lasciando la riga «annullata»; non quelli già partiti, e non al moderatore', function (): void {
    $pending = r12Notification(['status' => NotificationStatus::Pending, 'send_at' => now()->addHour()]);
    $sent = r12Notification(['status' => NotificationStatus::Sent, 'send_at' => now()->subHour(), 'sent_at' => now()->subHour()]);
    $url = '/api/v1/editorial/scheduled-notifications/'.$pending->id.'/cancel';
    r12Gate($this, 'POST', $url);
    r12Client($this, $this->s->moderator)->postJson($url)->assertForbidden();
    expect($pending->fresh()->status)->toBe(NotificationStatus::Pending);

    r12Client($this, $this->s->admin)->postJson($url)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.abilities.cancel', false)
        ->assertJsonPath('meta.message', __('admin.notifications.notification_cancelled'));
    expect($pending->fresh()->status)->toBe(NotificationStatus::Cancelled);

    // Già fermato o già partito: la cronaca non si corregge.
    r12Client($this, $this->superAdmin)->postJson($url)->assertForbidden();
    r12Client($this, $this->superAdmin)->postJson('/api/v1/editorial/scheduled-notifications/'.$sent->id.'/cancel')->assertForbidden();
    expect($sent->fresh()->status)->toBe(NotificationStatus::Sent);
    r12Client($this, $this->superAdmin)->postJson('/api/v1/editorial/scheduled-notifications/99999999/cancel')->assertNotFound();
});

it('carica gli invii senza una query per riga', function (): void {
    foreach (range(1, 3) as $index) {
        r12Notification(['notifiable_type' => 'event_occurrence', 'notifiable_id' => $this->s->occurrenceA->id, 'send_at' => now()->addHours($index)]);
    }
    $client = r12Client($this, $this->s->admin);
    $client->getJson('/api/v1/editorial/scheduled-notifications')->assertOk();

    DB::enableQueryLog();
    $client->getJson('/api/v1/editorial/scheduled-notifications')->assertOk()->assertJsonCount(3, 'data');
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();
    foreach (range(1, 6) as $index) {
        r12Notification(['notifiable_type' => 'event_occurrence', 'notifiable_id' => $this->s->occurrenceB->id, 'send_at' => now()->addDays($index)]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $client->getJson('/api/v1/editorial/scheduled-notifications')->assertOk()->assertJsonCount(9, 'data');
    expect(count(DB::getQueryLog()))->toBe($few);
});

// -------------------------------------------------------------------- cron

it('mostra cron e programmazioni ad admin e super admin, in sola lettura', function (): void {
    // Il comando arriva come elenco di argomenti (`'crontab' '-l'`): il falso lo prende per somiglianza.
    Process::fake(['*crontab*' => Process::result(output: '* * * * * cd '.base_path().' && php artisan schedule:run')]);
    r12Gate($this, 'GET', '/api/v1/editorial/scheduled-operations');
    r12Client($this, $this->s->moderator)->getJson('/api/v1/editorial/scheduled-operations')->assertForbidden();

    foreach ($this->admins as $person) {
        $data = r12Client($this, $person)->getJson('/api/v1/editorial/scheduled-operations')->assertOk()->json('data');
        expect($data['installed'])->toBe(['readable' => true, 'scheduler' => true, 'worker' => false])
            ->and($data['cron']['scheduler'])->toContain('flock -n', 'schedule:run')
            ->and($data['cron']['worker'])->toContain('queue:work')
            ->and($data['queue'])->toBe(config('queue.default'))
            ->and($data['social'])->toBe([])
            ->and(collect($data['tasks'])->pluck('name')->implode(' '))->toContain('notifications:send', 'social:publish-due', 'backup:run')
            ->and($data['tasks'][0])->toHaveKeys(['name', 'explanation', 'command', 'expression', 'next_due_date', 'last_finished', 'last_failed', 'monitored']);
    }
});

it('dà nelle opzioni i tipi degli invii e degli import con le etichette del sito', function (): void {
    $options = r12Client($this, $this->s->moderator)->getJson('/api/v1/editorial/options')->assertOk()->json('data');

    expect(collect($options['notification_types'])->pluck('value')->all())->toBe(NotificationType::values())
        ->and(collect($options['import_source_types'])->pluck('value')->all())->toBe(ImportSourceType::values())
        ->and(collect($options['import_run_statuses'])->firstWhere('value', 'failed')['label'])->toBe(ImportRunStatus::Failed->label());
});
