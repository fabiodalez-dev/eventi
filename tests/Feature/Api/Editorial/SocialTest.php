<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\SocialPublicationStatus as Status;
use App\Enums\UserRole;
use App\Jobs\Social\PublishSocial;
use App\Models\City;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Services\Social\SocialCatalog;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R9 — il Social della redazione nell'app (R-SO-01…07, R-DB-10, R-WB-03,
 * R-WB-04): lo studio della pagina «Social» del pannello senza locale e
 * senza dimostrativi, la pubblicazione e lo storico, le impostazioni senza i
 * segreti, «Social di oggi». La griglia: moderatore (dentro la redazione ma
 * 403, come `Social::canAccess`), admin, super admin, referente di un
 * locale e utente comune (404), token non sbloccato (423). Nessuna rotta è
 * pericolosa: con la password vecchia di 16 minuti si pubblica lo stesso.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    freezeLocal($this->s->city, '2026-09-10 12:00');
    $this->demo = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-11 22:00',
        event: ['is_demo' => true, 'title' => 'Serata dimostrativa del portico'], venue: $this->s->venueA);
    $this->url = '/api/v1/editorial/social';
});

afterEach(fn () => Carbon::setTestNow());

/** La griglia di una rotta: fuori la gente comune (404), chiuso senza sblocco (423), il moderatore 403, dentro admin e super admin. */
function r9Grid(object $test, string $method, string $url, array $body = []): void
{
    foreach ([$test->s->ownerA, $test->s->plainUser] as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->s->moderator))->json($method, $url, $body)
        ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
}

function r9As(object $test, mixed $user = null, int $passwordMinutesAgo = 0): object
{
    return EditorialSession::as($test, EditorialSession::token($user ?? $test->s->admin, passwordMinutesAgo: $passwordMinutesAgo));
}

/** Un pacchetto della redazione preparato dall'app per le serate di un giorno. */
function r9Batch(object $test, array $occurrences, string $date = '2026-09-11', array $extra = []): string
{
    return (string) r9As($test)->postJson($test->url.'/batches', ['date' => $date, 'occurrences' => $occurrences, ...$extra])
        ->assertCreated()->json('data.id');
}

/** Il collegamento con il solo Telegram verificato, come nei test della pagina. */
function r9Telegram(object $test): SocialConnection
{
    return SocialConnection::create(['city_id' => $test->s->city->id, 'telegram_bot_token' => '123:test-secret', 'telegram_chat_id' => '@incittatest',
        'telegram_enabled' => true, 'telegram_verified_at' => now()]);
}

// ------------------------------------------------------------------ studio

it('apre lo studio solo ad admin e super admin, con tutte le serate della città e senza dimostrativi', function (): void {
    r9Grid($this, 'GET', $this->url.'/catalog');

    foreach ([$this->s->admin, $this->superAdmin] as $staff) {
        $response = r9As($this, $staff)->getJson($this->url.'/catalog')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.period', 'next30')
            ->assertJsonPath('meta.from', '2026-09-10')
            ->assertJsonPath('meta.until', '2026-10-09')
            ->assertJsonPath('meta.city.slug', $this->s->city->slug);
        $ids = collect($response->json('data'))->pluck('id');
        // Come la pagina della redazione: le serate di tutti i locali della città, i dimostrativi no.
        expect($ids->all())->toEqualCanonicalizing([$this->s->occurrenceA->id, $this->s->occurrenceB->id])
            ->and($response->json('meta.total'))->toBe(app(SocialCatalog::class)->total($this->s->city, '2026-09-10', '2026-10-09'));
    }

    r9As($this)->getJson($this->url.'/catalog?period=custom&from=2026-09-12&until=2026-09-12')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->s->occurrenceB->id)
        ->assertJsonPath('data.0.venue', $this->s->venueB->name);
    r9As($this)->getJson($this->url.'/catalog?search=Reading')->assertOk()->assertJsonPath('meta.total', 1);
});

it('lavora sulla città scelta, anche non attiva, e su quella di un evento', function (): void {
    $other = City::factory()->create(['slug' => 'vicenza-prova', 'name' => 'Vicenza', 'is_active' => false]);

    r9As($this)->getJson($this->url.'/catalog?city='.$other->slug)->assertOk()
        ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.city.slug', 'vicenza-prova');
    // Con l'evento lo studio si restringe a lui e parte dalla sua prossima data, nella sua città.
    r9As($this)->getJson($this->url.'/day?city='.$other->slug.'&event='.$this->s->publishedEventB->id)->assertOk()
        ->assertJsonPath('meta.date', '2026-09-12')
        ->assertJsonPath('meta.event.id', $this->s->publishedEventB->id)
        ->assertJsonPath('meta.city.slug', $this->s->city->slug)
        ->assertJsonPath('data.0.id', $this->s->occurrenceB->id);
    r9As($this)->getJson($this->url.'/catalog?event=999999')->assertNotFound();
});

it('conta le serate del calendario e apre il giorno sulla prima serata utile', function (): void {
    r9Grid($this, 'GET', $this->url.'/calendar?month=2026-09');

    $days = collect(r9As($this)->getJson($this->url.'/calendar?month=2026-09')->assertOk()
        ->assertJsonCount(42, 'data')->assertJsonPath('meta.next', '2026-10')->json('data'))->keyBy('date');
    expect($days['2026-09-11']['count'])->toBe(1)
        ->and($days['2026-09-12']['count'])->toBe(1)
        ->and($days['2026-09-10']['today'])->toBeTrue();

    r9As($this)->getJson($this->url.'/day')->assertOk()
        ->assertJsonPath('meta.date', '2026-09-11')
        ->assertJsonPath('meta.max_selection', 100)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->s->occurrenceA->id);
});

it('prepara un pacchetto della redazione, lo riapre con il testo automatico e ne dà immagini e ZIP', function (): void {
    r9Grid($this, 'POST', $this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id]]);
    expect(SocialBatch::query()->count())->toBe(0);

    $id = r9Batch($this, [$this->s->occurrenceA->id], extra: ['format' => 'square', 'image_fit' => 'cover', 'title' => 'Stasera si balla']);
    $batch = SocialBatch::query()->findOrFail($id);
    expect($batch->venue_id)->toBeNull()
        ->and($batch->user_id)->toBe($this->s->admin->id)
        ->and($batch->format)->toBe('square')
        ->and($batch->options)->toMatchArray(['image_fit' => 'cover', 'title' => 'Stasera si balla']);

    $show = r9As($this)->getJson($this->url.'/batches/'.$id)->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.in_history', false)
        ->assertJsonPath('data.city.slug', $this->s->city->slug)
        ->json('data');
    expect($show['captions'])->toHaveCount(1)
        ->and($show['captions'][0])->toContain('Concerto di musica popolare')
        ->and($show['items'][0]['url'])->toContain('signature=');

    r9As($this)->getJson($this->url.'/batches')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.keep', 12);
    $image = r9As($this)->get($this->url.'/batches/'.$id.'/images/0')->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/jpeg');
    r9As($this)->get($this->url.'/batches/'.$id.'/images/3')->assertNotFound();
    r9As($this, $this->superAdmin)->get($this->url.'/batches/'.$id.'/zip')->assertOk()->assertDownload();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->get($this->url.'/batches/'.$id.'/zip')->assertForbidden();
});

it('applica le regole dello studio: un giorno, serate preparabili, titolo con una sola serata', function (): void {
    $second = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-11 23:00', event: ['title' => 'Jam session del giovedì'], venue: $this->s->venueB);

    r9As($this)->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id, $second->id], 'title' => 'Troppo'])
        ->assertUnprocessable()->assertJsonPath('error.fields.title.0', __('social.title_single'));
    // Una serata di un altro giorno o dimostrativa non è fra quelle preparabili: 403, come la pagina.
    r9As($this)->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceB->id]])->assertForbidden();
    r9As($this)->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->demo->id]])->assertForbidden();
    r9As($this)->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => []])->assertUnprocessable();
    expect(SocialBatch::query()->count())->toBe(0);

    r9Batch($this, [$this->s->occurrenceA->id, $second->id]);
    expect(SocialBatch::query()->sole()->items)->toHaveCount(2);
});

it('dà l\'anteprima di una serata ad admin e super admin', function (): void {
    $url = $this->url.'/dates/'.$this->s->occurrenceA->id.'/preview';
    r9Grid($this, 'GET', $url);

    foreach ([$this->s->admin, $this->superAdmin] as $staff) {
        $response = r9As($this, $staff)->get($url)->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('image/jpeg');
    }
    r9As($this)->get($this->url.'/dates/999999/preview')->assertNotFound();
});

// ------------------------------------------------------------ social di oggi

it('mostra le serate di oggi e prepara il pacchetto di oggi come il riquadro del riepilogo', function (): void {
    r9Grid($this, 'GET', $this->url.'/today');
    r9As($this)->getJson($this->url.'/today')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.date', '2026-09-10');
    r9As($this)->postJson($this->url.'/today')->assertUnprocessable()->assertJsonPath('error.fields.generate.0', __('social.choose_events'));

    freezeLocal($this->s->city, '2026-09-11 12:00');
    r9As($this)->getJson($this->url.'/today')->assertOk()
        ->assertJsonPath('meta.date', '2026-09-11')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->s->occurrenceA->id);
    r9Grid($this, 'POST', $this->url.'/today');

    $batch = r9As($this)->postJson($this->url.'/today')->assertCreated()
        ->assertJsonPath('data.format.value', 'portrait')
        ->assertJsonPath('data.date', '2026-09-11')
        ->json('data');
    expect(SocialBatch::query()->findOrFail($batch['id'])->items)->toHaveCount(1);
});

// ----------------------------------------------------------- pubblicazione

it('pubblica subito sui canali verificati, una volta sola, anche senza password recente', function (): void {
    $id = r9Batch($this, [$this->s->occurrenceA->id]);
    r9Grid($this, 'POST', $this->url.'/batches/'.$id.'/publish');

    // Senza collegamento verificato è un messaggio sul campo, come sotto il pulsante della pagina.
    r9As($this)->postJson($this->url.'/batches/'.$id.'/publish')->assertUnprocessable()
        ->assertJsonPath('error.fields.publish.0', __('social.not_connected'));

    r9Telegram($this);
    r9As($this, passwordMinutesAgo: 16)->postJson($this->url.'/batches/'.$id.'/publish')->assertOk()
        ->assertJsonPath('meta.message', __('social.queued'))
        ->assertJsonPath('data.0.platform', 'telegram')
        ->assertJsonPath('data.0.status.value', 'queued');
    r9As($this)->postJson($this->url.'/batches/'.$id.'/publish')->assertOk();
    expect(SocialPublication::query()->count())->toBe(1);
    Queue::assertPushed(PublishSocial::class, 1);
    r9As($this)->getJson($this->url.'/batches/'.$id)->assertOk()->assertJsonPath('data.in_history', true);
});

it('programma nel fuso della città, riprogramma e annulla dallo storico', function (): void {
    r9Telegram($this);
    $id = r9Batch($this, [$this->s->occurrenceA->id]);
    $url = $this->url.'/batches/'.$id.'/schedule';
    r9Grid($this, 'POST', $url, ['scheduled_at' => '2026-09-10T18:30']);

    r9As($this)->postJson($url, ['scheduled_at' => '2026-09-10T11:00'])->assertUnprocessable()
        ->assertJsonPath('error.fields.scheduled_at.0', __('social.schedule.future'));
    r9As($this)->postJson($url, ['scheduled_at' => 'domani'])->assertUnprocessable();

    r9As($this)->postJson($url, ['scheduled_at' => '2026-09-10T18:30'])->assertCreated()
        ->assertJsonPath('data.0.status.value', 'scheduled')
        ->assertJsonPath('meta.message', __('social.schedule.done'));
    $publication = SocialPublication::query()->sole();
    expect($publication->scheduled_at->timestamp)->toBe(CarbonImmutable::parse('2026-09-10 18:30', $this->s->city->timezone)->timestamp);
    Queue::assertNothingPushed();
    // Già nello storico: si cambia da lì.
    r9As($this)->postJson($url, ['scheduled_at' => '2026-09-10T19:00'])->assertUnprocessable()
        ->assertJsonPath('error.fields.scheduled_at.0', __('social.schedule.already'));

    r9Grid($this, 'GET', $this->url.'/publications');
    r9As($this)->getJson($this->url.'/publications')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 30)
        ->assertJsonPath('data.0.id', $publication->id)
        ->assertJsonPath('data.0.part', 1)
        ->assertJsonPath('data.0.status.label', __('social.scheduled_status'))
        ->assertJsonPath('data.0.abilities', ['reschedule' => true, 'cancel' => true, 'retry' => false]);

    r9Grid($this, 'PATCH', $this->url.'/publications/'.$publication->id, ['scheduled_at' => '2026-09-10T20:00']);
    r9As($this)->patchJson($this->url.'/publications/'.$publication->id, ['scheduled_at' => '2026-09-10T20:00'])->assertOk()
        ->assertJsonPath('meta.changed', true)->assertJsonPath('meta.message', __('social.schedule.updated'));
    expect($publication->fresh()->scheduled_at->timestamp)->toBe(CarbonImmutable::parse('2026-09-10 20:00', $this->s->city->timezone)->timestamp);

    r9Grid($this, 'POST', $this->url.'/publications/'.$publication->id.'/cancel');
    r9As($this)->postJson($this->url.'/publications/'.$publication->id.'/cancel')->assertOk()
        ->assertJsonPath('data.status.value', 'cancelled')
        ->assertJsonPath('data.abilities.reschedule', false);
    // Annullata non si riprogramma più: la riga resta com'è.
    r9As($this)->patchJson($this->url.'/publications/'.$publication->id, ['scheduled_at' => '2026-09-10T21:00'])->assertOk()
        ->assertJsonPath('meta.changed', false);
    expect($publication->fresh()->status)->toBe(Status::Cancelled);
    r9As($this)->postJson($this->url.'/publications/999999/cancel')->assertNotFound();
});

it('riprova una pubblicazione non riuscita solo con il pacchetto, e lo storico la mostra anche senza', function (): void {
    $connection = r9Telegram($this);
    $id = r9Batch($this, [$this->s->occurrenceA->id]);
    $failed = SocialPublication::create(['social_batch_id' => $id, 'social_connection_id' => $connection->id, 'platform' => 'telegram', 'part' => 0,
        'dedupe_key' => 'r9:failed', 'status' => Status::Failed->value, 'error' => 'Telegram non ha risposto']);
    $published = SocialPublication::create(['social_batch_id' => $id, 'social_connection_id' => $connection->id, 'platform' => 'telegram', 'part' => 1,
        'dedupe_key' => 'r9:published', 'status' => Status::Published->value]);

    r9Grid($this, 'POST', $this->url.'/publications/'.$failed->id.'/retry');
    r9As($this)->postJson($this->url.'/publications/'.$published->id.'/retry')->assertUnprocessable();
    r9As($this)->postJson($this->url.'/publications/'.$failed->id.'/retry')->assertOk()
        ->assertJsonPath('meta.changed', true)
        ->assertJsonPath('data.status.value', 'queued');
    Queue::assertPushed(PublishSocial::class, 1);

    // Un evento cambiato ferma il nuovo tentativo, con il messaggio del pannello.
    $failed->update(['status' => Status::Uncertain->value]);
    $this->s->occurrenceA->update(['status' => OccurrenceStatus::Cancelled]);
    r9As($this)->postJson($this->url.'/publications/'.$failed->id.'/retry')->assertUnprocessable()
        ->assertJsonPath('error.fields.publish.0', __('social.stale'));

    // La pulizia notturna toglie il pacchetto: la pubblicazione resta nello storico, senza «Riprova».
    SocialBatch::query()->findOrFail($id)->delete();
    $row = collect(r9As($this)->getJson($this->url.'/publications')->assertOk()->assertJsonPath('meta.total', 2)->json('data'))->firstWhere('id', $failed->id);
    expect($row['batch_gone'])->toBeTrue()->and($row['batch_id'])->toBeNull()->and($row['abilities']['retry'])->toBeFalse();
    r9As($this)->postJson($this->url.'/publications/'.$failed->id.'/retry')->assertUnprocessable()
        ->assertJsonPath('error.fields.publish.0', __('social.studio.batch_gone'));
    r9As($this)->getJson($this->url.'/publications?filter[status]=published')->assertOk()->assertJsonPath('meta.total', 1);
});

// ---------------------------------------------------------- impostazioni

it('legge le impostazioni senza mai restituire i segreti', function (): void {
    r9Grid($this, 'GET', $this->url.'/settings');
    r9As($this)->getJson($this->url.'/settings')->assertOk()
        ->assertJsonPath('data.status.exists', false)
        ->assertJsonPath('data.values.graph_version', 'v25.0')
        ->assertJsonPath('data.values.publish_time', '09:00')
        ->assertJsonPath('data.values.graphic_options.image_fit', 'contain')
        ->assertJsonPath('data.web_path', 'admin/social-settings');

    SocialConnection::create(['city_id' => $this->s->city->id, 'app_id' => '12345', 'app_secret' => 'private-app-secret', 'access_token' => 'page-private-token',
        'telegram_bot_token' => '123:test-secret', 'page_id' => '500', 'graph_version' => 'v25.0', 'facebook_enabled' => true, 'verified_at' => now()]);
    $response = r9As($this, $this->superAdmin)->getJson($this->url.'/settings')->assertOk()
        ->assertJsonPath('data.values.app_id', '12345')
        ->assertJsonPath('data.values.page_id', '500')
        ->assertJsonPath('data.status.app_secret_saved', true)
        ->assertJsonPath('data.status.access_token_saved', true)
        ->assertJsonPath('data.status.telegram_bot_token_saved', true);
    expect($response->getContent())->not->toContain('private-app-secret')->not->toContain('page-private-token')->not->toContain('123:test-secret')
        ->and(collect($response->json('data.tokens'))->pluck('token')->all())->toContain(':data', ':eventi', ':link');
});

it('salva le impostazioni dall\'app con le regole della pagina e rifiuta i segreti', function (): void {
    SocialConnection::create(['city_id' => $this->s->city->id, 'app_id' => '12345', 'app_secret' => 'private-app-secret', 'access_token' => 'page-private-token',
        'page_id' => '500', 'graph_version' => 'v25.0', 'facebook_enabled' => true, 'verified_at' => now(), 'automatic' => true]);
    $body = [
        'app_id' => '12345', 'city_id' => $this->s->city->id, 'page_id' => '500', 'instagram_id' => null, 'graph_version' => 'v25.0',
        'facebook_enabled' => true, 'instagram_enabled' => false, 'telegram_chat_id' => null, 'telegram_enabled' => false, 'automatic' => true,
        'graphic_options' => ['monochrome' => false, 'show_address' => true, 'show_price' => false, 'image_fit' => 'cover'],
        'publish_time' => '10:30', 'caption' => 'Il :data a :citta: :eventi :link',
    ];
    r9Grid($this, 'PUT', $this->url.'/settings', $body);

    r9As($this)->putJson($this->url.'/settings', [...$body, 'app_secret' => 'nuovo'])->assertUnprocessable()
        ->assertJsonPath('error.fields.app_secret.0', __('pannelli-social.app.secret_web_only'));
    r9As($this)->putJson($this->url.'/settings', [...$body, 'telegram_bot_token' => '1:abc'])->assertUnprocessable();
    r9As($this)->putJson($this->url.'/settings', [...$body, 'caption' => ':inventato'])->assertUnprocessable()
        ->assertJsonPath('error.fields.caption.0', __('social.invalid_tokens', ['tokens' => 'inventato']));
    r9As($this)->putJson($this->url.'/settings', [...$body, 'publish_time' => '25:00'])->assertUnprocessable();

    // Gli stessi dati Meta: la verifica resta, l'automatico anche.
    r9As($this)->putJson($this->url.'/settings', $body)->assertOk()
        ->assertJsonPath('meta.message', __('social.saved'))
        ->assertJsonPath('meta.warning', null)
        ->assertJsonPath('data.values.publish_time', '10:30')
        ->assertJsonPath('data.values.graphic_options.image_fit', 'cover');
    $connection = SocialConnection::query()->sole();
    expect($connection->access_token)->toBe('page-private-token')
        ->and($connection->app_secret)->toBe('private-app-secret')
        ->and($connection->verified_at)->not->toBeNull()
        ->and($connection->automatic)->toBeTrue();

    // Un'altra Pagina toglie la verifica, e senza canali verificati l'automatico si spegne con l'avviso.
    r9As($this)->putJson($this->url.'/settings', [...$body, 'page_id' => '501'])->assertOk()
        ->assertJsonPath('meta.warning', __('social.not_connected'))
        ->assertJsonPath('data.values.automatic', false)
        ->assertJsonPath('data.status.meta_verified_at', null);
    expect($connection->fresh()->access_token)->toBe('page-private-token');
});

it('verifica Meta e Telegram sui valori salvati', function (): void {
    SocialConnection::create(['city_id' => $this->s->city->id, 'page_id' => '500', 'access_token' => 'page-private-token', 'graph_version' => 'v25.0',
        'facebook_enabled' => true, 'telegram_bot_token' => '123:test-secret', 'telegram_chat_id' => '@incittatest', 'telegram_enabled' => true]);
    r9Grid($this, 'POST', $this->url.'/settings/verify');
    r9Grid($this, 'POST', $this->url.'/settings/telegram/verify');

    Http::fake([
        'graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 190]], 400)->push(['id' => '500', 'name' => 'Pagina']),
        '*/getMe' => Http::sequence()->push(['ok' => false], 403)->push(['ok' => true, 'result' => ['id' => 123]]),
        '*/getChat' => Http::response(['ok' => true, 'result' => ['type' => 'channel']]),
        '*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]]),
    ]);
    r9As($this)->postJson($this->url.'/settings/verify')->assertUnprocessable()->assertJsonPath('error.fields.verify.0', __('social.verify_failed'));
    r9As($this)->postJson($this->url.'/settings/verify')->assertOk()->assertJsonPath('meta.message', __('social.verified'));
    expect(SocialConnection::query()->sole()->verified_at)->not->toBeNull();

    r9As($this)->postJson($this->url.'/settings/telegram/verify')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['telegram']]]);
    r9As($this)->postJson($this->url.'/settings/telegram/verify')->assertOk()->assertJsonPath('meta.message', __('pannelli-social.app.verified_telegram'));
    expect(SocialConnection::query()->sole()->telegram_verified_at)->not->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
});

it('dà l\'indirizzo del collegamento Meta da aprire nel browser e scollega', function (): void {
    r9Grid($this, 'GET', $this->url.'/meta/connect');
    r9As($this)->getJson($this->url.'/meta/connect')->assertUnprocessable()->assertJsonPath('error.fields.meta.0', __('pannelli-social.app.meta_not_ready'));

    $connection = SocialConnection::create(['city_id' => $this->s->city->id, 'app_id' => '12345', 'app_secret' => 'private-app-secret', 'graph_version' => 'v25.0',
        'access_token' => 'page-private-token', 'facebook_enabled' => true, 'verified_at' => now(), 'automatic' => true]);
    $response = r9As($this)->getJson($this->url.'/meta/connect')->assertOk()
        ->assertJsonPath('data.url', route('social.meta.connect'))
        ->assertJsonPath('data.callback_url', route('social.meta.callback'));
    expect($response->getContent())->not->toContain('private-app-secret');

    r9Grid($this, 'POST', $this->url.'/meta/disconnect');
    r9As($this)->postJson($this->url.'/meta/disconnect')->assertOk()
        ->assertJsonPath('data.status.access_token_saved', false)
        ->assertJsonPath('data.values.automatic', false);
    $fresh = $connection->fresh();
    expect($fresh->access_token)->toBeNull()->and($fresh->verified_at)->toBeNull()->and($fresh->automatic)->toBeFalse()
        ->and($fresh->app_secret)->toBe('private-app-secret');
});
