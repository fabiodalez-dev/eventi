<?php

declare(strict_types=1);

use App\Enums\ConsentCategory;
use App\Enums\UserRole;
use App\Mail\MailConfigurationTest as MailTestMessage;
use App\Models\ConsentScript;
use App\Models\CookieDeclaration;
use App\Models\NotificationText;
use App\Models\Redirect;
use App\Services\Cache\FrontendCacheConfiguration;
use App\Services\Settings\NotificationTextEditor;
use App\Settings\FrontendCacheSettings;
use App\Settings\MailSettings;
use App\Settings\NewsletterSettings;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R13 — la configurazione tecnica nell'app della redazione (R-SY-08…14):
 * testi delle email, posta, newsletter, script di consenso, cookie,
 * indirizzi cambiati, cache. Gli stessi permessi delle pagine del pannello
 * (admin e super admin; posta e newsletter solo super admin; il moderatore
 * riceve 403), le stesse regole (classi condivise con il pannello) e la
 * decisione 5: i segreti non escono e non si scrivono dall'app.
 *
 * La griglia: moderatore, admin, super admin, referente di un locale,
 * utente comune, token non sbloccato (423). Nessuna rotta del pacchetto è
 * pericolosa: con la password vecchia di 16 minuti le scritture passano.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
});

/** Fuori la gente comune (404), chiuso senza sblocco (423). */
function r13Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->superAdmin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

/** Le richieste di una persona con lo sblocco valido ma la password di 16 minuti fa. */
function r13As(mixed $test, mixed $user): mixed
{
    return EditorialSession::as($test, EditorialSession::token($user, passwordMinutesAgo: 16));
}

// ------------------------------------------------------------ testi email

it('mostra i testi delle email ad admin e super admin, con la newsletter solo al super admin', function (): void {
    r13Gate($this, 'GET', '/api/v1/editorial/notification-texts');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/notification-texts')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

    $admin = r13As($this, $this->s->admin)->getJson('/api/v1/editorial/notification-texts')->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonPath('data.newsletter_included', false)->json('data.groups');
    $keys = collect($admin)->flatMap(fn (array $group): array => array_column($group['texts'], 'key'));
    expect($keys)->toContain('notifications.reminder.subject')
        ->and($keys)->not->toContain('notifications.weekend.subject')
        ->and($admin[0])->toHaveKeys(['key', 'label', 'texts'])
        ->and($admin[0]['texts'][0])->toHaveKeys(['key', 'label', 'default', 'value', 'modified', 'variables', 'preview', 'rows', 'revision']);

    $super = r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/notification-texts')->assertOk()
        ->assertJsonPath('data.newsletter_included', true)->json('data.groups');
    expect(collect($super)->flatMap(fn (array $group): array => array_column($group['texts'], 'key')))->toContain('notifications.weekend.subject');
});

it('salva un testo con la revisione letta, e vuoto o uguale all\'originale torna all\'originale', function (): void {
    $key = 'notifications.moved.subject';
    $url = '/api/v1/editorial/notification-texts/'.$key;
    $revision = NotificationTextEditor::revision($key);

    r13Gate($this, 'PUT', $url, ['value' => 'Cambiato :title', 'revision' => $revision]);
    r13As($this, $this->s->moderator)->putJson($url, ['value' => 'Cambiato :title', 'revision' => $revision])->assertForbidden();

    $row = r13As($this, $this->s->admin)->putJson($url, ['value' => 'Nuovo orario per :title', 'revision' => $revision])->assertOk()
        ->assertJsonPath('data.value', 'Nuovo orario per :title')
        ->assertJsonPath('data.modified', true)
        ->assertJsonPath('meta.message', __('notification_texts.saved', ['scritti' => 1, 'ripristinati' => 0]))
        ->json('data');
    expect(NotificationText::query()->where('key', $key)->value('updated_by'))->toBe($this->s->admin->id)
        ->and($row['revision'])->not->toBe($revision)
        ->and($row['preview'])->toBe('Nuovo orario per '.__('notification_texts.examples.title'));

    // La revisione di prima non vale più: qualcuno ha scritto nel frattempo.
    r13As($this, $this->superAdmin)->putJson($url, ['value' => 'Altro :title', 'revision' => $revision])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['revision']]]);

    // Un segnaposto che l'originale non ha.
    r13As($this, $this->s->admin)->putJson($url, ['value' => 'Nuovo :inventato', 'revision' => $row['revision']])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['value']]]);

    // Vuoto: torna all'originale, la riga sparisce.
    r13As($this, $this->s->admin)->putJson($url, ['value' => '', 'revision' => $row['revision']])->assertOk()
        ->assertJsonPath('data.modified', false)
        ->assertJsonPath('meta.message', __('notification_texts.saved', ['scritti' => 0, 'ripristinati' => 1]));
    expect(NotificationText::query()->where('key', $key)->exists())->toBeFalse();
});

it('non lascia scrivere all\'admin i testi della newsletter né chiavi che non esistono', function (): void {
    $key = 'notifications.weekend.subject';
    $body = ['value' => 'Weekend', 'revision' => NotificationTextEditor::revision($key)];

    r13As($this, $this->s->admin)->putJson('/api/v1/editorial/notification-texts/'.$key, $body)->assertNotFound();
    r13As($this, $this->s->admin)->putJson('/api/v1/editorial/notification-texts/notifications.inventata.subject', $body)->assertNotFound();
    r13As($this, $this->superAdmin)->putJson('/api/v1/editorial/notification-texts/'.$key, $body)->assertOk();
    expect(NotificationText::query()->where('key', $key)->value('value'))->toBe('Weekend');
});

it('rende l\'esempio di un testo non salvato e segnala i segnaposti sconosciuti', function (): void {
    r13Gate($this, 'POST', '/api/v1/editorial/notification-texts/preview', ['key' => 'notifications.moved.subject', 'value' => 'x']);

    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/notification-texts/preview', ['key' => 'notifications.moved.subject', 'value' => 'Spostato :title :boh'])
        ->assertOk()
        ->assertJsonPath('data.preview', 'Spostato '.__('notification_texts.examples.title').' :boh')
        ->assertJsonPath('data.unknown', ['boh']);
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/notification-texts/preview', ['key' => 'notifications.weekend.line', 'value' => 'x'])->assertNotFound();
    expect(NotificationText::query()->count())->toBe(0);
});

it('riporta all\'originale solo i testi che la persona vede', function (): void {
    NotificationText::query()->create(['key' => 'notifications.weekend.line', 'value' => 'Scritto dal super admin :count']);
    NotificationText::query()->create(['key' => 'notifications.moved.subject', 'value' => 'Riscritto :title']);
    NotificationText::dimenticaLaCache();

    r13Gate($this, 'POST', '/api/v1/editorial/notification-texts/reset');
    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/notification-texts/reset')->assertForbidden();
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/notification-texts/reset')->assertOk()
        ->assertJsonPath('meta.message', __('notification_texts.reset_done'));

    expect(NotificationText::query()->pluck('key')->all())->toBe(['notifications.weekend.line']);
});

// ------------------------------------------------------------------ posta

it('mostra la posta solo al super admin e non fa mai uscire la password', function (): void {
    $settings = app(MailSettings::class);
    $settings->host = 'smtp.example.com';
    $settings->password = 'una-password-riconoscibile';
    $settings->save();

    r13Gate($this, 'GET', '/api/v1/editorial/mail');
    foreach ([$this->s->moderator, $this->s->admin] as $person) {
        r13As($this, $person)->getJson('/api/v1/editorial/mail')->assertForbidden();
    }

    $response = r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/mail')->assertOk()
        ->assertJsonPath('data.host', 'smtp.example.com')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.status.title', __('mail_settings.status.env'))
        ->assertJsonPath('data.recipient', $this->superAdmin->email);
    expect($response->getContent())->not->toContain('una-password-riconoscibile')
        ->and($response->json('data'))->not->toHaveKey('password');
});

it('manda la prova con la configurazione salvata e la segna come provata', function (): void {
    Mail::fake();
    $settings = app(MailSettings::class);
    $settings->password = 'segreta';
    $settings->save();

    r13Gate($this, 'POST', '/api/v1/editorial/mail/test');
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/mail/test')->assertForbidden();
    Mail::assertNothingSent();

    r13As($this, $this->superAdmin)->postJson('/api/v1/editorial/mail/test')->assertOk()
        ->assertJsonPath('data.result.sent', true)
        ->assertJsonPath('data.result.title', __('mail_settings.test.sent', ['email' => $this->superAdmin->email]))
        ->assertJsonPath('data.mail.verified', true)
        ->assertJsonPath('data.mail.enabled', false);

    Mail::assertSent(MailTestMessage::class, fn (MailTestMessage $m): bool => $m->hasTo($this->superAdmin->email));
    // Provata ma non accesa: l'interruttore resta sul sito.
    expect(app(MailSettings::class)->refresh()->verified())->toBeTrue()
        ->and(app(MailSettings::class)->active())->toBeFalse();
});

it('riporta per esteso il rifiuto del server di posta, senza segnare la prova', function (): void {
    $settings = app(MailSettings::class);
    $settings->host = '127.0.0.1';
    $settings->port = 1;
    $settings->encryption = null;
    $settings->save();

    $result = r13As($this, $this->superAdmin)->postJson('/api/v1/editorial/mail/test')->assertOk()
        ->assertJsonPath('data.result.sent', false)
        ->assertJsonPath('data.result.title', __('mail_settings.test.failed'))
        ->assertJsonPath('data.mail.verified', false)
        ->json('data.result');
    expect($result['detail'])->not->toBe('');
});

// ------------------------------------------------------------- newsletter

it('legge e salva la newsletter solo per il super admin, con le regole del pannello', function (): void {
    $body = ['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend', 'heading' => '', 'line' => 'Scopri :count eventi', 'action' => null];

    r13Gate($this, 'GET', '/api/v1/editorial/newsletter');
    r13Gate($this, 'PUT', '/api/v1/editorial/newsletter', $body);
    foreach ([$this->s->moderator, $this->s->admin] as $person) {
        r13As($this, $person)->getJson('/api/v1/editorial/newsletter')->assertForbidden();
        r13As($this, $person)->putJson('/api/v1/editorial/newsletter', $body)->assertForbidden();
    }

    r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/newsletter')->assertOk()
        ->assertJsonPath('data.weekdays.0', ['value' => 1, 'label' => 'Lunedì'])
        ->assertJsonPath('data.texts.line.max', 2000)
        ->assertJsonPath('data.texts.line.variables.0.name', 'count');

    r13As($this, $this->superAdmin)->putJson('/api/v1/editorial/newsletter', $body)->assertOk()
        ->assertJsonPath('data.time', '18:45')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.texts.subject.value', 'Il tuo weekend')
        ->assertJsonPath('meta.message', 'Impostazioni newsletter salvate');
    expect(app(NewsletterSettings::class)->weekday)->toBe(5)
        ->and(Features::newsletterActive())->toBeTrue()
        ->and(NotificationText::query()->where('key', 'notifications.weekend.subject')->value('value'))->toBe('Il tuo weekend')
        // Il titolo vuoto torna all'originale: nessuna riga.
        ->and(NotificationText::query()->where('key', 'notifications.weekend.heading')->exists())->toBeFalse();

    r13As($this, $this->superAdmin)->putJson('/api/v1/editorial/newsletter', [...$body, 'line' => 'Variabile :inesistente'])
        ->assertStatus(422)->assertJsonPath('error.fields.line.0', 'Variabili non riconosciute: inesistente');
    r13As($this, $this->superAdmin)->putJson('/api/v1/editorial/newsletter', [...$body, 'weekday' => 9, 'time' => '25:00', 'max_items' => 0])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['weekday', 'time', 'max_items']]]);
    r13As($this, $this->superAdmin)->putJson('/api/v1/editorial/newsletter', [...$body, 'enabled' => false])->assertOk();
    expect(Features::newsletterActive())->toBeFalse();
});

// ----------------------------------------------------- script di consenso

it('mostra gli script di consenso in sola lettura ad admin e super admin', function (): void {
    $script = ConsentScript::query()->create(['name' => 'Statistiche', 'category' => ConsentCategory::Statistics, 'code' => 'window.stats = true;', 'enabled' => true, 'sort_order' => 2]);
    ConsentScript::query()->create(['name' => 'Annunci', 'category' => ConsentCategory::Marketing, 'src' => 'https://example.test/ads.js', 'sort_order' => 1]);

    r13Gate($this, 'GET', '/api/v1/editorial/consent-scripts');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/consent-scripts')->assertForbidden();

    foreach ([$this->s->admin, $this->superAdmin] as $person) {
        $rows = r13As($this, $person)->getJson('/api/v1/editorial/consent-scripts')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        expect(array_column($rows, 'name'))->toBe(['Annunci', 'Statistiche'])
            ->and($rows[1])->toMatchArray(['category' => 'statistics', 'enabled' => true, 'has_code' => true])
            ->and($rows[1])->not->toHaveKey('code');
    }

    r13As($this, $this->s->admin)->getJson('/api/v1/editorial/consent-scripts/'.$script->id)->assertOk()->assertJsonPath('data.code', 'window.stats = true;');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/consent-scripts/'.$script->id)->assertForbidden();
    r13As($this, $this->s->admin)->getJson('/api/v1/editorial/consent-scripts/999999')->assertNotFound();
    // Nessuna scrittura dall'app (decisione 5).
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/consent-scripts', ['name' => 'x'])->assertStatus(405);
    r13As($this, $this->s->admin)->patchJson('/api/v1/editorial/consent-scripts/'.$script->id, ['enabled' => false])->assertStatus(405);
});

// ------------------------------------------------------------------ cookie

it('elenca i cookie per finalità con i conteggi delle schede e la ricerca', function (): void {
    // Le righe che le migrazioni scrivono per il sito vero non servono qui.
    CookieDeclaration::query()->delete();
    CookieDeclaration::query()->create(['category' => 'necessary', 'name' => 'XSRF-TOKEN', 'purpose' => 'Protegge i moduli', 'sort_order' => 2]);
    CookieDeclaration::query()->create(['category' => 'necessary', 'name' => 'incitta_session', 'purpose' => 'La sessione', 'duration' => 'la sessione', 'sort_order' => 1]);
    CookieDeclaration::query()->create(['category' => 'marketing', 'name' => 'ads', 'provider' => 'Annunci Spa', 'purpose' => 'Annunci']);

    r13Gate($this, 'GET', '/api/v1/editorial/cookies');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/cookies')->assertForbidden();

    $response = r13As($this, $this->s->admin)->getJson('/api/v1/editorial/cookies')->assertOk()
        ->assertJsonPath('meta.tab', 'necessary')
        ->assertJsonPath('meta.tabs', ['necessary' => 2, 'statistics' => 0, 'marketing' => 1])
        ->assertJsonPath('meta.categories.0.value', 'necessary');
    expect(array_column($response->json('data'), 'name'))->toBe(['incitta_session', 'XSRF-TOKEN'])
        ->and($response->json('data.0.abilities'))->toBe(['reorder' => true]);

    r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/cookies?tab=marketing&q=Annunci')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.provider', 'Annunci Spa');
    r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/cookies?tab=marketing&q=nessuno')->assertOk()->assertJsonPath('meta.total', 0);
});

it('aggiunge un cookie con le regole del modulo e il nome unico nella finalità', function (): void {
    CookieDeclaration::query()->delete();
    $body = ['category' => 'statistics', 'name' => '_pk_id', 'provider' => null, 'purpose' => 'Conteggio anonimo', 'duration' => '13 mesi'];

    r13Gate($this, 'POST', '/api/v1/editorial/cookies', $body);
    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/cookies', $body)->assertForbidden();

    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies', $body)->assertCreated()
        ->assertJsonPath('data.name', '_pk_id')->assertJsonPath('data.category', 'statistics')->assertJsonPath('data.provider', null);
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies', $body)
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies', ['category' => 'altro', 'name' => str_repeat('a', 256), 'purpose' => '', 'duration' => str_repeat('d', 65)])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['category', 'name', 'purpose', 'duration']]]);
    // Lo stesso nome in un'altra finalità va bene, come nel database.
    r13As($this, $this->superAdmin)->postJson('/api/v1/editorial/cookies', [...$body, 'category' => 'marketing'])->assertCreated();
    expect(CookieDeclaration::query()->count())->toBe(2);
});

it('riordina una scheda dei cookie come il trascinamento del pannello', function (): void {
    CookieDeclaration::query()->delete();
    $a = CookieDeclaration::query()->create(['category' => 'necessary', 'name' => 'a', 'purpose' => 'a', 'sort_order' => 1]);
    $b = CookieDeclaration::query()->create(['category' => 'necessary', 'name' => 'b', 'purpose' => 'b', 'sort_order' => 2]);
    $other = CookieDeclaration::query()->create(['category' => 'marketing', 'name' => 'c', 'purpose' => 'c', 'sort_order' => 5]);
    $body = ['category' => 'necessary', 'ids' => [$b->id, $a->id]];

    r13Gate($this, 'POST', '/api/v1/editorial/cookies/order', $body);
    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/cookies/order', $body)->assertForbidden();

    $rows = r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies/order', $body)->assertOk()->json('data');
    expect(array_column($rows, 'name'))->toBe(['b', 'a'])
        ->and($b->fresh()->getAttribute('sort_order'))->toBe(1)
        ->and($a->fresh()->getAttribute('sort_order'))->toBe(2);

    // Un id di un'altra finalità non entra nell'ordine di questa.
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cookies/order', ['category' => 'necessary', 'ids' => [$other->id, $a->id]])
        ->assertStatus(422);
    expect($other->fresh()->getAttribute('sort_order'))->toBe(5);
});

// ------------------------------------------------------ indirizzi cambiati

it('elenca gli indirizzi cambiati dal passaggio più recente, con ricerca e ordinamenti', function (): void {
    $old = Redirect::factory()->create(['from_path' => '/vecchio', 'to_path' => '/nuovo', 'hits' => 9]);
    $old->forceFill(['last_hit_at' => now()->subDays(3)])->save();
    $recent = Redirect::factory()->create(['from_path' => '/volantino', 'to_path' => '/eventi', 'city_id' => $this->s->city->id, 'hits' => 2]);
    $recent->forceFill(['last_hit_at' => now()->subHour()])->save();
    Redirect::factory()->create(['from_path' => '/mai', 'to_path' => '/eventi', 'hits' => 0]);

    r13Gate($this, 'GET', '/api/v1/editorial/redirects');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/redirects')->assertForbidden();

    $rows = r13As($this, $this->s->admin)->getJson('/api/v1/editorial/redirects')->assertOk()
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.abilities.create', true)->json('data');
    expect(array_column($rows, 'from_path'))->toBe(['/volantino', '/vecchio', '/mai'])
        ->and($rows[0]['city'])->toBe(['id' => $this->s->city->id, 'name' => $this->s->city->name])
        ->and($rows[0]['status_label'])->toBe(__('redirects.status.permanent'))
        ->and($rows[2]['last_hit_at'])->toBeNull();

    expect(array_column(r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/redirects?sort=-hits')->json('data'), 'from_path'))->toBe(['/vecchio', '/volantino', '/mai'])
        ->and(array_column(r13As($this, $this->superAdmin)->getJson('/api/v1/editorial/redirects?q=volant')->json('data'), 'from_path'))->toBe(['/volantino']);
});

it('aggiunge un indirizzo cambiato con le regole del modulo del pannello', function (): void {
    $body = ['from_path' => '/sezione-tolta', 'to_path' => '/eventi', 'city_id' => $this->s->city->id, 'status' => 302, 'is_wildcard' => false];

    r13Gate($this, 'POST', '/api/v1/editorial/redirects', $body);
    r13Gate($this, 'GET', '/api/v1/editorial/redirects/options');
    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/redirects', $body)->assertForbidden();
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/redirects/options')->assertForbidden();

    r13As($this, $this->s->admin)->getJson('/api/v1/editorial/redirects/options')->assertOk()
        ->assertJsonPath('data.statuses.1', ['value' => 302, 'label' => __('redirects.status.temporary')])
        ->assertJsonPath('data.default_status', 301)
        ->assertJsonFragment(['id' => $this->s->city->id, 'name' => $this->s->city->name]);

    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/redirects', $body)->assertCreated()
        ->assertJsonPath('data.from_path', '/sezione-tolta')->assertJsonPath('data.status', 302)->assertJsonPath('data.hits', 0);
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/redirects', $body)
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['from_path']]]);
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/redirects', [...$body, 'from_path' => '/altro', 'to_path' => '//evil.example', 'status' => 307, 'city_id' => 999999])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['to_path', 'status', 'city_id']]]);
    // Senza città vale per tutte.
    r13As($this, $this->superAdmin)->postJson('/api/v1/editorial/redirects', ['from_path' => '/vecchio/*', 'to_path' => '/nuovo', 'status' => 301, 'is_wildcard' => true])->assertCreated()
        ->assertJsonPath('data.city', null)->assertJsonPath('data.is_wildcard', true);
    expect(Redirect::query()->count())->toBe(2);
});

// ------------------------------------------------------------------- cache

it('mostra la cache ad admin e super admin senza la password di Redis', function (): void {
    $settings = app(FrontendCacheSettings::class);
    $settings->redis_password = 'private-redis-secret';
    $settings->save();

    r13Gate($this, 'GET', '/api/v1/editorial/cache');
    r13As($this, $this->s->moderator)->getJson('/api/v1/editorial/cache')->assertForbidden();

    $response = r13As($this, $this->s->admin)->getJson('/api/v1/editorial/cache')->assertOk()
        ->assertJsonPath('data.redis.port', 6379)
        ->assertJsonPath('data.redis.has_password', true)
        ->assertJsonStructure(['data' => ['managed', 'enabled', 'store', 'store_label', 'ttl_minutes', 'environment' => ['redis_extension', 'litespeed', 'server']]]);
    expect($response->getContent())->not->toContain('private-redis-secret');
});

it('svuota le cache del sito conservando lock e impostazioni', function (): void {
    Cache::put('booking-lock', 'preserved', 60);

    r13Gate($this, 'POST', '/api/v1/editorial/cache/clear');
    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/cache/clear')->assertForbidden();
    expect(Cache::get('frontend_cache_revision'))->toBeNull();

    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cache/clear')->assertOk()
        ->assertJsonPath('meta.message', __('cache_settings.cleared'));
    expect(Cache::get('booking-lock'))->toBe('preserved')
        ->and(Cache::get('frontend_cache_revision'))->toBeString();
});

it('verifica Redis con la configurazione salvata senza attivarlo', function (): void {
    $this->mock(FrontendCacheConfiguration::class)->shouldReceive('verifyRedis')->twice()
        ->withArgs(fn (array $values): bool => $values['redis_port'] === 6379)
        ->andReturn(true, false);

    r13As($this, $this->s->moderator)->postJson('/api/v1/editorial/cache/verify')->assertForbidden();
    r13As($this, $this->s->admin)->postJson('/api/v1/editorial/cache/verify')->assertOk()
        ->assertJsonPath('data.ok', true)->assertJsonPath('meta.message', __('cache_settings.redis_ok'));
    r13As($this, $this->superAdmin)->postJson('/api/v1/editorial/cache/verify')->assertOk()
        ->assertJsonPath('data.ok', false)->assertJsonPath('meta.message', __('cache_settings.redis_failed'));
    expect(app(FrontendCacheSettings::class)->managed)->toBeFalse();
});

it('non ha rotte pericolose: nessuna eliminazione in queste pagine del pannello', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'api.v1.editorial.settings.'))
        ->values();

    expect($names)->toHaveCount(19)
        ->and($names->filter(fn (string $name): bool => str_ends_with($name, '.destroy')))->toBeEmpty();
});
