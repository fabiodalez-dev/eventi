<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Enums\NotificationStatus;
use App\Enums\SocialPublicationStatus;
use App\Enums\UserRole;
use App\Jobs\Import\ImportSourceJob;
use App\Models\CookieDeclaration;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Models\NotificationText;
use App\Models\ScheduledNotification;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Import\HostResolver;
use App\Services\Settings\NotificationTextEditor;
use App\Settings\MailSettings;
use App\Support\Import\ImportPreviewLimiter;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\IcsFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * Le correzioni della revisione di sicurezza dell'ondata 3 della redazione
 * (R9, R12, R13, R14) nell'API dell'app:
 *
 * 1. le credenziali di un import non escono cambiando l'origine dell'indirizzo;
 * 2. la password recente sulle scritture che mettono qualcosa davanti a tutti;
 * 3. il registro di sicurezza, con chi, che cosa, prima e dopo, mai i segreti;
 * 4. la prova della posta dice per esteso solo l'errore del trasporto;
 * 5. l'anteprima dell'import con un tempo complessivo e un limite globale;
 * 6. nessun `utente:password@` nell'indirizzo, e l'indirizzo segreto mascherato.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->source = ImportSource::factory()->create([
        'city_id' => $this->s->city->id,
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->s->category->id,
        'credentials' => 'utente:segreto',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function w3As(mixed $test, User $user, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as($test, EditorialSession::token($user, passwordMinutesAgo: $passwordMinutesAgo));
}

/** La riga del registro di sicurezza scritta per `$evento`, l'ultima. */
function w3Log(string $evento): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->latest('id')->first();
}

/** Un calendario servito da qualunque host, che registra le richieste ricevute. */
function w3Calendar(): void
{
    Http::fake(['*' => Http::response(IcsFixtures::body('internal-entries'), 200, ['Content-Type' => 'text/calendar'])]);
}

// ------------------------------------------------------- 1. credenziali e origine

it('rifiuta dall app il cambio di origine di una sorgente con credenziali, e nessuna richiesta porta Authorization al nuovo host', function (): void {
    w3Calendar();
    $url = '/api/v1/editorial/import-sources/'.$this->source->id;
    $client = w3As($this, $this->s->admin);

    foreach (['https://attaccante.test/x.ics', 'http://calendario.test/eventi.ics', 'https://calendario.test:8443/eventi.ics'] as $other) {
        $client->patchJson($url, ['url' => $other])->assertUnprocessable()
            ->assertJsonPath('error.fields.url.0', __('admin.import.credentials_origin_app'));
    }
    expect($this->source->fresh()->url)->toBe(IcsFixtures::URL)
        ->and($this->source->fresh()->credentials)->toBe('utente:segreto');

    $client->postJson($url.'/preview')->assertOk();
    Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), IcsFixtures::URL));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attaccante.test') && $request->hasHeader('Authorization'));

    // La stessa origine con un percorso diverso va bene: le credenziali restano e partono verso lo stesso host.
    $client->patchJson($url, ['url' => 'https://calendario.test/altro.ics'])->assertOk();
    expect($this->source->fresh()->credentials)->toBe('utente:segreto');

    // Senza credenziali l'origine si cambia anche dall'app.
    $plain = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'url' => IcsFixtures::URL, 'default_category_id' => $this->s->category->id]);
    $client->patchJson('/api/v1/editorial/import-sources/'.$plain->id, ['url' => 'https://altro-comune.test/eventi.ics'])->assertOk();
    expect($plain->fresh()->url)->toBe('https://altro-comune.test/eventi.ics');
});

// ------------------------------------------------------- 2. password recente

it('chiede la password recente alle scritture che arrivano a tutti', function (): void {
    SocialConnection::create(['city_id' => $this->s->city->id, 'page_id' => '500', 'access_token' => 'page-private-token', 'facebook_enabled' => true, 'verified_at' => now()]);
    $key = 'notifications.moved.subject';
    $settingsBody = [
        'app_id' => '1', 'city_id' => $this->s->city->id, 'page_id' => '500', 'instagram_id' => null, 'graph_version' => 'v25.0',
        'facebook_enabled' => true, 'instagram_enabled' => false, 'telegram_chat_id' => null, 'telegram_enabled' => false, 'automatic' => true,
        'graphic_options' => ['monochrome' => true, 'show_address' => true, 'show_price' => true, 'image_fit' => 'contain'],
        'publish_time' => '09:00', 'caption' => 'Il :data a :citta: :eventi :link',
    ];
    $newsletter = ['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend', 'heading' => '', 'line' => 'Scopri :count eventi', 'action' => null];
    $source = '/api/v1/editorial/import-sources';
    $calls = [
        ['PUT', '/api/v1/editorial/social/settings', $settingsBody, $this->s->admin],
        ['POST', '/api/v1/editorial/social/meta/disconnect', [], $this->s->admin],
        ['PUT', '/api/v1/editorial/newsletter', $newsletter, $this->superAdmin],
        ['PUT', '/api/v1/editorial/notification-texts/'.$key, ['value' => 'Cambiato :title', 'revision' => NotificationTextEditor::revision($key)], $this->s->admin],
        ['POST', '/api/v1/editorial/notification-texts/reset', [], $this->s->admin],
        ['POST', $source.'/'.$this->source->id.'/run', [], $this->s->admin],
        ['POST', $source, ['city_id' => $this->s->city->id, 'type' => 'ics', 'url' => IcsFixtures::URL, 'default_category_id' => $this->s->category->id], $this->s->admin],
        ['PATCH', $source.'/'.$this->source->id, ['url' => 'https://calendario.test/nuovo.ics'], $this->s->admin],
    ];
    Queue::fake();

    foreach ($calls as [$method, $url, $body, $person]) {
        w3As($this, $person, passwordMinutesAgo: 16)->json($method, $url, $body)
            ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    }
    Queue::assertNothingPushed();
    expect(SocialConnection::query()->sole()->access_token)->toBe('page-private-token')
        ->and(ImportSource::query()->count())->toBe(1)
        ->and($this->source->fresh()->url)->toBe(IcsFixtures::URL)
        ->and(NotificationText::query()->count())->toBe(0);

    foreach ($calls as [$method, $url, $body, $person]) {
        expect(w3As($this, $person)->json($method, $url, $body)->status())->toBeLessThan(300);
    }
});

// ------------------------------------------------------- 3. registro di sicurezza

it('scrive nel registro chi cambia le sorgenti di import, con prima e dopo e senza le credenziali', function (): void {
    Queue::fake();
    $client = w3As($this, $this->s->admin);

    $created = $client->postJson('/api/v1/editorial/import-sources', ['city_id' => $this->s->city->id, 'type' => 'ics', 'url' => 'https://comune.test/eventi.ics', 'default_category_id' => $this->s->category->id])
        ->assertCreated()->json('data.id');
    $log = w3Log('import_sorgente_creata');
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->s->admin->id)
        ->and($log->getProperty('canale'))->toBe('app')
        ->and($log->getProperty('id'))->toBe($created)
        ->and($log->getProperty('dopo')['url'])->toBe('https://comune.test/eventi.ics');

    $client->patchJson('/api/v1/editorial/import-sources/'.$this->source->id, ['url' => 'https://calendario.test/nuovo.ics', 'is_active' => false])->assertOk();
    $log = w3Log('import_sorgente_modificata');
    expect($log->getProperty('id'))->toBe($this->source->id)
        ->and($log->getProperty('prima'))->toBe(['url' => 'https://calendario.test/…', 'is_active' => true])
        ->and($log->getProperty('dopo'))->toBe(['url' => 'https://calendario.test/…', 'is_active' => false])
        ->and(json_encode($log->properties))->not->toContain('segreto');

    $client->postJson('/api/v1/editorial/import-sources/'.$this->source->id.'/run')->assertStatus(202);
    expect(w3Log('import_sorgente_eseguita')?->getProperty('id'))->toBe($this->source->id);

    $client->deleteJson('/api/v1/editorial/import-sources/'.$created)->assertOk();
    expect(w3Log('import_sorgente_eliminata')?->getProperty('prima')['url'])->toBe('https://comune.test/eventi.ics');
});

it('scrive nel registro le impostazioni social e lo scollegamento di Meta, senza i segreti', function (): void {
    SocialConnection::create(['city_id' => $this->s->city->id, 'app_id' => '12345', 'app_secret' => 'private-app-secret', 'access_token' => 'page-private-token',
        'page_id' => '500', 'graph_version' => 'v25.0', 'facebook_enabled' => true, 'verified_at' => now(), 'automatic' => false, 'publish_time' => '09:00', 'caption' => 'Il :data']);
    $body = [
        'app_id' => '12345', 'city_id' => $this->s->city->id, 'page_id' => '500', 'instagram_id' => null, 'graph_version' => 'v25.0',
        'facebook_enabled' => true, 'instagram_enabled' => false, 'telegram_chat_id' => null, 'telegram_enabled' => false, 'automatic' => true,
        'graphic_options' => ['monochrome' => true, 'show_address' => true, 'show_price' => true, 'image_fit' => 'contain'],
        'publish_time' => '10:30', 'caption' => 'Il :data a :citta',
    ];

    w3As($this, $this->s->admin)->putJson('/api/v1/editorial/social/settings', $body)->assertOk();
    $log = w3Log('social_impostazioni_cambiate');
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->s->admin->id)
        ->and($log->getProperty('canale'))->toBe('app')
        ->and($log->getProperty('prima'))->toMatchArray(['automatic' => false, 'caption' => 'Il :data'])
        ->and($log->getProperty('dopo'))->toMatchArray(['automatic' => true, 'caption' => 'Il :data a :citta'])
        ->and(json_encode($log->properties))->not->toContain('private-app-secret')->not->toContain('page-private-token');

    w3As($this, $this->superAdmin)->postJson('/api/v1/editorial/social/meta/disconnect')->assertOk();
    $log = w3Log('meta_scollegato');
    expect($log->causer_id)->toBe($this->superAdmin->id)
        ->and($log->getProperty('prima'))->toBe(['access_token_saved' => true, 'verified' => true, 'automatic' => true])
        ->and(json_encode($log->properties))->not->toContain('page-private-token');
});

it('scrive nel registro testi delle email, ripristino e newsletter', function (): void {
    $key = 'notifications.moved.subject';
    w3As($this, $this->s->admin)->putJson('/api/v1/editorial/notification-texts/'.$key, ['value' => 'Nuovo orario per :title', 'revision' => NotificationTextEditor::revision($key)])->assertOk();
    $log = w3Log('testi_email_cambiati');
    expect($log->causer_id)->toBe($this->s->admin->id)
        ->and($log->getProperty('canale'))->toBe('app')
        ->and($log->getProperty('dopo'))->toBe([$key => 'Nuovo orario per :title'])
        ->and(array_keys($log->getProperty('prima')))->toBe([$key]);

    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/notification-texts/reset')->assertOk();
    expect(w3Log('testi_email_ripristinati')->getProperty('prima'))->toBe([$key => 'Nuovo orario per :title']);

    w3As($this, $this->superAdmin)->putJson('/api/v1/editorial/newsletter', ['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend', 'heading' => '', 'line' => 'Scopri :count eventi', 'action' => null])->assertOk();
    $log = w3Log('newsletter_cambiata');
    expect($log->causer_id)->toBe($this->superAdmin->id)
        ->and($log->getProperty('dopo'))->toMatchArray(['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend']);
});

it('scrive nel registro redirect, cookie e svuotamento della cache', function (): void {
    CookieDeclaration::query()->delete();
    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/redirects', ['from_path' => '/sezione-tolta', 'to_path' => '/eventi', 'city_id' => $this->s->city->id, 'status' => 302, 'is_wildcard' => false])->assertCreated();
    expect(w3Log('redirect_creato')->getProperty('dopo'))->toMatchArray(['from_path' => '/sezione-tolta', 'to_path' => '/eventi', 'status' => 302])
        ->and(w3Log('redirect_creato')->causer_id)->toBe($this->s->admin->id);

    $id = w3As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies', ['category' => 'statistics', 'name' => '_pk_id', 'provider' => null, 'purpose' => 'Conteggio anonimo', 'duration' => '13 mesi'])
        ->assertCreated()->json('data.id');
    expect(w3Log('cookie_creato')->getProperty('dopo'))->toMatchArray(['name' => '_pk_id', 'category' => 'statistics']);
    $other = CookieDeclaration::query()->create(['category' => 'statistics', 'name' => 'b', 'purpose' => 'b', 'sort_order' => 9]);
    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies/order', ['category' => 'statistics', 'ids' => [$other->id, $id]])->assertOk();
    expect(w3Log('cookie_riordinati')->getProperty('dopo'))->toBe([$other->id, $id]);

    w3As($this, $this->superAdmin)->postJson('/api/v1/editorial/cache/clear')->assertOk();
    expect(w3Log('cache_svuotata')?->causer_id)->toBe($this->superAdmin->id);
});

it('scrive nel registro l annullamento degli invii e la riprogrammazione e l annullamento dei post', function (): void {
    $notification = ScheduledNotification::factory()->create(['status' => NotificationStatus::Pending, 'send_at' => now()->addHour()]);
    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/scheduled-notifications/'.$notification->id.'/cancel')->assertOk();
    $log = w3Log('invio_programmato_annullato');
    expect($log->getProperty('id'))->toBe($notification->id)
        ->and($log->getProperty('prima'))->toBe(['status' => 'pending'])
        ->and($log->getProperty('dopo'))->toBe(['status' => 'cancelled']);

    Carbon::setTestNow(Carbon::parse('2026-09-10 10:00', $this->s->city->timezone));
    $connection = SocialConnection::create(['city_id' => $this->s->city->id, 'telegram_bot_token' => '123:test-secret', 'telegram_chat_id' => '@incittatest', 'telegram_enabled' => true, 'telegram_verified_at' => now()]);
    $publication = SocialPublication::query()->create([
        'social_connection_id' => $connection->id, 'platform' => 'telegram', 'part' => 1, 'status' => SocialPublicationStatus::Scheduled->value,
        'scheduled_at' => now()->addHours(5), 'dedupe_key' => 'w3:scheduled',
    ]);
    w3As($this, $this->s->admin)->patchJson('/api/v1/editorial/social/publications/'.$publication->id, ['scheduled_at' => '2026-09-10T20:00'])->assertOk();
    $log = w3Log('post_social_riprogrammato');
    expect($log->getProperty('id'))->toBe($publication->id)
        ->and($log->getProperty('dopo')['scheduled_at'])->toBe(Carbon::parse('2026-09-10 20:00', $this->s->city->timezone)->utc()->toIso8601String());

    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/social/publications/'.$publication->id.'/cancel')->assertOk();
    expect(w3Log('post_social_annullato')->getProperty('dopo'))->toBe(['status' => 'cancelled']);
});

// ------------------------------------------------------- 4. prova della posta

it('dice per esteso solo l errore del trasporto: per il resto un messaggio generico e il rapporto', function (): void {
    Exceptions::fake();
    $settings = app(MailSettings::class);
    $settings->host = '127.0.0.1';
    $settings->port = 1;
    $settings->encryption = null;
    $settings->save();
    Event::listen(MessageSending::class, function (): never {
        throw new RuntimeException('SQLSTATE[HY000]: insert into settings values (impronta-segreta)');
    });

    $result = w3As($this, $this->superAdmin)->postJson('/api/v1/editorial/mail/test')->assertOk()->json('data.result');
    expect($result['sent'])->toBeFalse()
        ->and($result['detail'])->toBe(__('mail_settings.test.internal_error'))
        ->and(json_encode($result))->not->toContain('impronta-segreta');
    Exceptions::assertReported(RuntimeException::class);
});

// ------------------------------------------------------- 5. anteprima

it('limita l anteprima a quindici secondi complessivi e a un solo redirect', function (): void {
    $seen = [];
    $slow = false;
    // Un solo finto server per tutte e due le prove: `Http::fake` somma gli stub e serve il primo.
    Http::fake(['*' => function (Request $request, array $options) use (&$seen, &$slow) {
        $seen[] = ['url' => $request->url(), 'timeout' => $options['timeout'] ?? null];
        if ($slow) {
            Carbon::setTestNow(now()->addSeconds(16));

            return Http::response('', 302, ['Location' => 'https://calendario.test/primo.ics']);
        }

        return match ($request->url()) {
            IcsFixtures::URL => Http::response('', 302, ['Location' => 'https://calendario.test/primo.ics']),
            'https://calendario.test/primo.ics' => Http::response('', 302, ['Location' => 'https://calendario.test/secondo.ics']),
            default => Http::response(IcsFixtures::body('internal-entries'), 200),
        };
    }]);

    $data = w3As($this, $this->s->admin)->postJson('/api/v1/editorial/import-sources/'.$this->source->id.'/preview')->assertOk()->json('data');
    expect($data['rows'])->toBe([])
        ->and($data['error'])->toBe(__('import.errors.http_status', ['status' => 302]))
        ->and(array_column($seen, 'url'))->toBe([IcsFixtures::URL, 'https://calendario.test/primo.ics'])
        ->and(max(array_column($seen, 'timeout')))->toBeLessThanOrEqual(15)
        // L'esecuzione oraria conserva i suoi tempi.
        ->and(config('import.timeout'))->toBe(30)
        ->and(config('import.max_redirects'))->toBe(3);

    // Il tempo è complessivo: un primo salto che ne consuma sedici non lascia partire il secondo.
    $seen = [];
    $slow = true;
    Carbon::setTestNow(now());
    $data = w3As($this, $this->superAdmin)->postJson('/api/v1/editorial/import-sources/'.$this->source->id.'/preview')->assertOk()->json('data');
    expect(array_column($seen, 'url'))->toBe([IcsFixtures::URL])
        ->and($data['error'])->toBe(__('import.errors.preview_timeout', ['seconds' => 15]));
});

it('ha un limite globale per le anteprime oltre a quello per persona', function (): void {
    w3Calendar();
    $url = '/api/v1/editorial/import-sources/'.$this->source->id.'/preview';
    $people = [$this->s->admin, $this->superAdmin];

    for ($i = 0; $i < ImportPreviewLimiter::GLOBAL_PER_MINUTE; $i++) {
        w3As($this, $people[$i % 2])->postJson($url)->assertOk();
    }
    $third = EditorialSession::user(UserRole::Admin);
    w3As($this, $third)->postJson($url)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    Http::assertSentCount(ImportPreviewLimiter::GLOBAL_PER_MINUTE);
});

// ------------------------------------------------------- 6. indirizzi segreti

it('rifiuta utente e password nell indirizzo', function (): void {
    $body = ['city_id' => $this->s->city->id, 'type' => 'ics', 'url' => 'https://utente:password@calendario.test/eventi.ics', 'default_category_id' => $this->s->category->id];
    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/import-sources', $body)->assertUnprocessable()
        ->assertJsonPath('error.fields.url.0', __('admin.import.url_userinfo'));
    $plain = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'url' => IcsFixtures::URL, 'default_category_id' => $this->s->category->id]);
    w3As($this, $this->s->admin)->patchJson('/api/v1/editorial/import-sources/'.$plain->id, ['url' => 'https://solo-utente@calendario.test/eventi.ics'])->assertUnprocessable()
        ->assertJsonPath('error.fields.url.0', __('admin.import.url_userinfo'));
});

it('mostra mascherato l indirizzo di una sorgente con credenziali o con un segreto nel percorso, anche negli errori', function (): void {
    $this->source->update(['last_status' => ImportRunStatus::Failed->value, 'last_error' => __('import.errors.unreachable', ['url' => IcsFixtures::URL, 'reason' => 'cURL error 6 for '.IcsFixtures::URL])]);
    ImportRun::factory()->create(['import_source_id' => $this->source->id, 'status' => ImportRunStatus::Failed->value, 'message' => 'Non raggiungibile: '.IcsFixtures::URL]);
    $google = 'https://calendar.google.com/calendar/ical/teatro%40gmail.com/private-0a1b2c3d4e5f60718293a4b5c6d7e8f9/basic.ics';
    $private = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'url' => $google, 'default_category_id' => $this->s->category->id]);
    $nextcloud = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'url' => 'https://cloud.test/remote.php/dav/public-calendars/Xy7kP3qL9mN2rT5w?export', 'default_category_id' => $this->s->category->id]);
    $public = ImportSource::factory()->create(['city_id' => $this->s->city->id, 'url' => 'https://comune.test/eventi/calendario.ics', 'default_category_id' => $this->s->category->id]);
    $client = w3As($this, $this->s->admin);

    $rows = collect($client->getJson('/api/v1/editorial/import-sources')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$this->source->id]['url'])->toBe('https://calendario.test/…')
        ->and($rows[$this->source->id]['url_masked'])->toBeTrue()
        ->and($rows[$this->source->id]['last_error'])->not->toContain('eventi.ics')->toContain('https://calendario.test/…')
        ->and($rows[$private->id]['url'])->toBe('https://calendar.google.com/…')
        ->and($rows[$nextcloud->id]['url'])->toBe('https://cloud.test/…')
        ->and($rows[$public->id]['url'])->toBe('https://comune.test/eventi/calendario.ics')
        ->and($rows[$public->id]['url_masked'])->toBeFalse();
    expect($client->getJson('/api/v1/editorial/import-sources/'.$private->id)->getContent())->not->toContain('private-0a1b2c3d');
    expect($client->getJson('/api/v1/editorial/import-sources/'.$this->source->id.'/runs')->json('data.0.message'))->toBe('Non raggiungibile: https://calendario.test/…');

    // L'errore dell'anteprima porta l'indirizzo mascherato.
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host for '.IcsFixtures::URL)]);
    $error = $client->postJson('/api/v1/editorial/import-sources/'.$this->source->id.'/preview')->assertOk()->json('data.error');
    expect($error)->not->toContain('eventi.ics')->toContain('https://calendario.test/…');

    // L'app che rimanda l'indirizzo mascherato così com'è non lo cambia.
    $client->patchJson('/api/v1/editorial/import-sources/'.$private->id, ['url' => 'https://calendar.google.com/…', 'is_active' => false])->assertOk()
        ->assertJsonPath('data.is_active', false);
    expect($private->fresh()->url)->toBe($google);
});

it('accoda ancora Esegui adesso con la password recente', function (): void {
    Queue::fake();
    w3As($this, $this->s->admin)->postJson('/api/v1/editorial/import-sources/'.$this->source->id.'/run')->assertStatus(202);
    Queue::assertPushed(ImportSourceJob::class, 1);
});
