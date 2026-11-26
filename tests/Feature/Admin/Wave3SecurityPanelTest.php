<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\SocialPublicationStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\CachePerformance;
use App\Filament\Admin\Pages\MailConfiguration;
use App\Filament\Admin\Pages\Newsletter;
use App\Filament\Admin\Pages\NotificationTexts;
use App\Filament\Admin\Pages\Social;
use App\Filament\Admin\Pages\SocialSettings;
use App\Filament\Admin\Resources\CookieDeclarations\Pages\ManageCookieDeclarations;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\ImportSources\Pages\CreateImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\EditImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\ListImportSources;
use App\Filament\Admin\Resources\Redirects\Pages\ManageRedirects;
use App\Filament\Admin\Resources\ScheduledNotifications\Pages\ListScheduledNotifications;
use App\Models\CookieDeclaration;
use App\Models\ImportSource;
use App\Models\ScheduledNotification;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Import\HostResolver;
use App\Services\Import\ImportRunner;
use App\Support\Import\ImportPreviewLimiter;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\IcsFixtures;

/*
 * Le correzioni della revisione di sicurezza dell'ondata 3 della redazione,
 * dove la stessa scrittura avviene nel pannello `/admin`: le credenziali di
 * un import che si cancellano al cambio di origine, l'indirizzo senza
 * `utente:password@`, la prova della posta, il tetto globale delle anteprime
 * e il registro di sicurezza delle scritture di configurazione.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($this->admin);
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->source = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
        'credentials' => 'utente:segreto',
    ]);
    $this->get(ImportSourceResource::getUrl('index'))->assertOk();
});

afterEach(fn () => Carbon::setTestNow());

function w3pLog(string $evento): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->latest('id')->first();
}

// ------------------------------------------------------- 1. credenziali e origine

it('cancella le credenziali quando l origine cambia senza che vengano riscritte, e nessuna richiesta porta Authorization al nuovo host', function (): void {
    Http::fake(['*' => Http::response(IcsFixtures::body('internal-entries'), 200, ['Content-Type' => 'text/calendar'])]);

    Livewire::test(EditImportSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['url' => 'https://attaccante.test/x.ics'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('admin.import.credentials_forgotten'));

    $fresh = $this->source->fresh();
    expect($fresh->url)->toBe('https://attaccante.test/x.ics')
        ->and($fresh->getRawOriginal('credentials'))->toBeNull();

    app(ImportRunner::class)->preview($fresh);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'attaccante.test'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
});

it('tiene le credenziali sulla stessa origine, e quelle riscritte insieme alla nuova origine', function (): void {
    Livewire::test(EditImportSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['url' => 'https://calendario.test/altro.ics'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->source->fresh()->credentials)->toBe('utente:segreto');

    Livewire::test(EditImportSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['url' => 'https://nuovo.test/eventi.ics', 'credentials' => 'altro:nuovo'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->source->fresh()->credentials)->toBe('altro:nuovo');
});

// ------------------------------------------------------- 6. utente:password@

it('rifiuta utente e password nell indirizzo anche nel modulo del pannello', function (): void {
    Livewire::test(CreateImportSource::class)
        ->fillForm(['city_id' => $this->city->getKey(), 'type' => 'ics', 'default_category_id' => $this->category->getKey(), 'url' => 'https://utente:password@calendario.test/eventi.ics'])
        ->call('create')
        ->assertHasFormErrors(['url']);
    expect(ImportSource::query()->count())->toBe(1);
});

// ------------------------------------------------------- 4. prova della posta

it('mostra nel pannello solo l errore del trasporto, per il resto un messaggio generico', function (): void {
    Exceptions::fake();
    Event::listen(MessageSending::class, function (): never {
        throw new RuntimeException('SQLSTATE[HY000]: impronta-segreta');
    });

    Livewire::test(MailConfiguration::class)
        ->fillForm(['host' => '127.0.0.1', 'port' => 1, 'encryption' => null, 'username' => 'tizio', 'password' => 'segreta'])
        ->call('inviaProva');

    $sent = new Notifications;
    $sent->mount();
    $notification = collect($sent->notifications->toArray())->firstWhere('title', __('mail_settings.test.failed'));
    expect($notification)->not->toBeNull()
        ->and($notification['body'])->toBe(__('mail_settings.test.internal_error'))
        ->and(json_encode($sent->notifications->toArray()))->not->toContain('impronta-segreta');
    Exceptions::assertReported(RuntimeException::class);
});

// ------------------------------------------------------- 5. anteprima

it('ferma l anteprima del pannello oltre il limite globale', function (): void {
    Http::fake(['*' => Http::response(IcsFixtures::body('internal-entries'), 200)]);
    for ($i = 0; $i < ImportPreviewLimiter::GLOBAL_PER_MINUTE; $i++) {
        RateLimiter::hit(ImportPreviewLimiter::GLOBAL_KEY, 60);
    }

    // La finestra si apre in un'altra richiesta: il suo contenuto si legge dall'azione montata.
    $action = Livewire::test(ListImportSources::class)->mountTableAction('preview', $this->source)->instance()->getMountedAction();
    expect($action->getModalContent()->render())->toContain(e(__('import.preview.busy')));
    Http::assertNothingSent();

    // Sotto il tetto l'anteprima parte, e conta.
    RateLimiter::clear(ImportPreviewLimiter::GLOBAL_KEY);
    $action = Livewire::test(ListImportSources::class)->mountTableAction('preview', $this->source)->instance()->getMountedAction();
    expect($action->getModalContent()->render())->toContain(e("Concerto del quartetto d'archi"))
        ->and(RateLimiter::attempts(ImportPreviewLimiter::GLOBAL_KEY))->toBeGreaterThanOrEqual(1);
});

// ------------------------------------------------------- 3. registro

it('scrive nel registro creazione, modifica, esecuzione e cancellazione delle sorgenti dal pannello', function (): void {
    Queue::fake();

    Livewire::test(CreateImportSource::class)
        ->fillForm(['city_id' => $this->city->getKey(), 'type' => 'ics', 'default_category_id' => $this->category->getKey(), 'url' => 'https://comune.test/eventi.ics'])
        ->call('create')->assertHasNoFormErrors();
    $created = ImportSource::query()->where('url', 'https://comune.test/eventi.ics')->sole();
    $log = w3pLog('import_sorgente_creata');
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->getProperty('id'))->toBe($created->id)
        ->and($log->getProperty('canale'))->toBeNull();

    Livewire::test(EditImportSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['url' => 'https://attaccante.test/x.ics'])
        ->call('save')->assertHasNoFormErrors();
    $log = w3pLog('import_sorgente_modificata');
    expect($log->getProperty('prima')['url'])->toBe('https://calendario.test/…')
        ->and($log->getProperty('dopo')['url'])->toBe('https://attaccante.test/x.ics')
        ->and($log->getProperty('credenziali'))->toBe('tolte')
        ->and(json_encode($log->properties))->not->toContain('segreto');

    Livewire::test(ListImportSources::class)->callTableAction('run', $created)->assertHasNoTableActionErrors();
    expect(w3pLog('import_sorgente_eseguita')?->getProperty('id'))->toBe($created->id);

    Livewire::test(EditImportSource::class, ['record' => $created->getRouteKey()])->callAction('delete');
    expect(w3pLog('import_sorgente_eliminata')?->getProperty('id'))->toBe($created->id);
});

it('scrive nel registro redirect, cookie, cache e annullamento degli invii dal pannello', function (): void {
    Livewire::test(ManageRedirects::class)->callAction('create', data: ['from_path' => '/vecchia', 'to_path' => '/eventi', 'status' => 301]);
    expect(w3pLog('redirect_creato')?->getProperty('dopo'))->toMatchArray(['from_path' => '/vecchia', 'to_path' => '/eventi']);

    CookieDeclaration::query()->delete();
    Livewire::test(ManageCookieDeclarations::class)->callAction('create', data: ['category' => 'statistics', 'name' => '_pk_id', 'purpose' => 'Conteggio anonimo']);
    expect(w3pLog('cookie_creato')?->getProperty('dopo'))->toMatchArray(['name' => '_pk_id']);
    $a = CookieDeclaration::query()->sole();
    $b = CookieDeclaration::query()->create(['category' => 'statistics', 'name' => 'b', 'purpose' => 'b', 'sort_order' => 2]);
    Livewire::test(ManageCookieDeclarations::class)->set('activeTab', 'statistics')->call('reorderTable', [(string) $b->id, (string) $a->id]);
    expect(w3pLog('cookie_riordinati')?->getProperty('dopo'))->toBe([$b->id, $a->id]);

    Livewire::test(CachePerformance::class)->call('clearCache');
    expect(w3pLog('cache_svuotata')?->causer_id)->toBe($this->admin->id);

    $notification = ScheduledNotification::factory()->create(['status' => NotificationStatus::Pending, 'send_at' => now()->addHour()]);
    Livewire::test(ListScheduledNotifications::class)->callTableAction('cancel', $notification)->assertHasNoTableActionErrors();
    expect(w3pLog('invio_programmato_annullato')?->getProperty('id'))->toBe($notification->id);
});

it('scrive nel registro impostazioni social, scollegamento di Meta, testi delle email e newsletter dal pannello', function (): void {
    SocialConnection::create(['city_id' => $this->city->getKey(), 'app_id' => '1', 'app_secret' => 'private-app-secret', 'access_token' => 'page-private-token', 'page_id' => '500',
        'graph_version' => 'v25.0', 'facebook_enabled' => true, 'verified_at' => now(), 'publish_time' => '09:00', 'caption' => 'Il :data']);
    Livewire::test(SocialSettings::class)->set('data.caption', 'Il :data a :citta')->call('save')->assertHasNoErrors();
    $log = w3pLog('social_impostazioni_cambiate');
    expect($log->getProperty('dopo'))->toMatchArray(['caption' => 'Il :data a :citta'])
        ->and(json_encode($log->properties))->not->toContain('private-app-secret')->not->toContain('page-private-token');
    Livewire::test(SocialSettings::class)->call('disconnectMeta');
    expect(w3pLog('meta_scollegato'))->not->toBeNull();

    Livewire::test(NotificationTexts::class)->set('data.notifications__moved__subject', 'Nuovo orario per :title')->call('salva')->assertHasNoErrors();
    expect(w3pLog('testi_email_cambiati')?->getProperty('dopo'))->toBe(['notifications.moved.subject' => 'Nuovo orario per :title']);
    Livewire::test(NotificationTexts::class)->call('ripristinaTutto');
    expect(w3pLog('testi_email_ripristinati'))->not->toBeNull();

    Livewire::test(Newsletter::class)->fillForm(['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend', 'heading' => 'Eventi scelti per te', 'line' => 'Scopri :count eventi', 'action' => 'Scopri il weekend'])
        ->call('save')->assertHasNoFormErrors();
    expect(w3pLog('newsletter_cambiata')?->getProperty('dopo'))->toMatchArray(['subject' => 'Il tuo weekend']);
});

it('scrive nel registro la riprogrammazione e l annullamento dei post dal pannello', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-10 10:00', $this->city->timezone));
    $connection = SocialConnection::create(['city_id' => $this->city->getKey(), 'telegram_bot_token' => '123:test-secret', 'telegram_chat_id' => '@incittatest', 'telegram_enabled' => true, 'telegram_verified_at' => now()]);
    $publication = SocialPublication::query()->create(['social_connection_id' => $connection->id, 'platform' => 'telegram', 'part' => 1, 'dedupe_key' => 'w3p:scheduled',
        'status' => SocialPublicationStatus::Scheduled->value, 'scheduled_at' => now()->addHours(5)]);

    Livewire::test(Social::class)->set('scheduledAt', '2026-09-10T20:00')->call('reschedulePublication', $publication->id)->assertHasNoErrors();
    expect(w3pLog('post_social_riprogrammato')?->getProperty('id'))->toBe($publication->id);
    Livewire::test(Social::class)->call('cancelScheduledPublication', $publication->id);
    expect(w3pLog('post_social_annullato')?->getProperty('id'))->toBe($publication->id);
});
