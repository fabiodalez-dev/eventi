<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Enums\FollowableType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Device;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Support\Features;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Il canale di consegna e le preferenze governate dall'app: iscrizione del
 * browser alle push (D54) con la scelta del canale fatta dal worker, le
 * preferenze dell'API (§15.8) che il pianificatore rilegge, e gli interessi
 * che alimentano il riepilogo settimanale dei locali seguiti.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('iscrive il browser alle push dalla pagina delle preferenze e il promemoria viaggia su push finché non lo revoca', function (): void {
    freezeLocal($this->city, '2026-09-01 12:00');
    Notification::fake();

    config()->set('webpush.vapid.public_key', 'chiave-pubblica-di-prova');
    config()->set('webpush.vapid.private_key', 'chiave-privata-di-prova');

    $iscrizione = [
        'endpoint' => 'https://push.example.org/browser-di-irene',
        'keys' => ['p256dh' => 'chiave-del-browser', 'auth' => 'segreto-del-browser'],
    ];

    // Chi non ha fatto l'accesso non può iscrivere un browser.
    $this->postJson(route('account.push.store'), $iscrizione)->assertUnauthorized();
    expect(Device::query()->count())->toBe(0);

    $irene = User::factory()->create();

    // 1. Apre le sue preferenze dal sito: l'interruttore delle push c'è.
    $firmato = (string) $this->actingAs($irene)->get('/notifiche')->assertRedirect()->headers->get('Location');

    $this->actingAs($irene)->get($firmato)
        ->assertOk()
        ->assertSee('data-push-toggle', escape: false);

    // 2. Un'iscrizione senza chiavi del browser è rifiutata, quella completa registrata.
    $this->actingAs($irene)->postJson(route('account.push.store'), ['endpoint' => $iscrizione['endpoint']])
        ->assertJsonValidationErrors(['keys']);

    $this->actingAs($irene)->postJson(route('account.push.store'), $iscrizione)->assertCreated();

    $browser = Device::query()->sole();

    expect($browser->user_id)->toBe($irene->getKey())
        ->and($browser->platform)->toBe(DevicePlatform::Web)
        ->and($browser->revoked_at)->toBeNull();

    // 3. Salva una data dal sito.
    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $this->actingAs($irene)->postJson(route('account.saved.store'), ['occurrence_id' => $data->getKey()])->assertSuccessful();

    expect(ScheduledNotification::query()->pending()->ofType('event_reminder')->count())->toBe(2);

    // 4. Il primo promemoria parte su push e nell'archivio, non per email.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTo($irene, ScheduledMessage::class, function (ScheduledMessage $n) use ($irene): bool {
        $via = $n->via($irene);

        return $n->channel === NotificationChannel::Push
            && in_array(WebPushChannel::class, $via, true)
            && in_array('database', $via, true)
            && ! in_array('mail', $via, true);
    });

    expect(ScheduledNotification::query()->ofType('event_reminder')->orderBy('send_at')->firstOrFail()->channel)->toBe(NotificationChannel::Push);

    // 5. Spegne le push da quel browser: la riga resta, revocata.
    $this->actingAs($irene)->deleteJson(route('account.push.destroy'), ['endpoint' => $iscrizione['endpoint']])->assertOk();

    expect($browser->refresh()->revoked_at)->not->toBeNull()
        ->and(Device::query()->count())->toBe(1);

    // 6. Il secondo promemoria torna all'email.
    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentToTimes($irene, ScheduledMessage::class, 2);

    $secondo = ScheduledNotification::query()->ofType('event_reminder')->orderByDesc('send_at')->firstOrFail();

    expect($secondo->status)->toBe(NotificationStatus::Sent)
        ->and($secondo->channel)->toBe(NotificationChannel::Mail);
});

it('governa dall app ore di silenzio, riepilogo, newsletter e anticipo dei promemoria, e il motore li applica', function (): void {
    freezeLocal($this->city, '2026-09-09 12:00'); // mercoledì
    Feature::for(Features::globalScope())->activate(Features::NEWSLETTER);

    $davide = User::factory()->create();
    $token = $davide->createToken('Telefono')->plainTextToken;

    // 1. Senza scelte: silenzio predefinito, niente marketing, annullamenti sempre attivi.
    $this->withToken($token)->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.quiet_hours', null)
        ->assertJsonPath('data.quiet_hours_effective', ['from' => '23:00', 'to' => '08:00'])
        ->assertJsonPath('data.marketing_opt_in', false)
        ->assertJsonPath('data.daily_digest', false)
        ->assertJsonPath('data.cancellations', true);

    // 2. Sceglie tutto in una volta.
    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', [
        'quiet_hours' => ['from' => '22:30', 'to' => '07:30'],
        'daily_digest' => true,
        'daily_digest_time' => '19:00',
        'marketing_opt_in' => true,
        'reminder_hours' => [48, 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.quiet_hours', ['from' => '22:30', 'to' => '07:30'])
        ->assertJsonPath('data.quiet_hours_effective', ['from' => '22:30', 'to' => '07:30'])
        ->assertJsonPath('data.daily_digest', true)
        ->assertJsonPath('data.reminder_hours', [48, 2])
        ->assertJsonPath('data.marketing_opt_in', true);

    $davide->refresh();

    expect($davide->marketing_opt_in_at)->not->toBeNull()
        ->and($davide->daily_digest_time)->toBe('19:00:00');

    // Un valore fuori scala è un errore, e non tocca ciò che è salvato.
    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', ['quiet_hours' => ['from' => '25:00', 'to' => '07:00']])
        ->assertUnprocessable();

    expect($davide->fresh()?->quiet_hours)->toBe(['from' => '22:30', 'to' => '07:30']);

    // 3. Il salvataggio di una data usa l'anticipo scelto.
    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00');
    $this->withToken($token)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])->assertCreated();

    $orari = ScheduledNotification::query()->ofType('event_reminder')->where('user_id', $davide->getKey())->orderBy('send_at')->get()
        ->map(fn (ScheduledNotification $riga): ?string => $riga->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->all();

    expect($orari)->toBe(['2026-09-11 21:00', '2026-09-13 19:00']);

    // 4. Il pianificatore programma riepilogo e newsletter.
    $this->artisan('notifications:plan')->assertSuccessful();

    expect(ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->where('user_id', $davide->getKey())->sole()->send_at?->setTimezone('Europe/Rome')->format('H:i'))->toBe('19:00')
        ->and(ScheduledNotification::query()->ofType(NotificationType::WeekendNewsletter->value)->where('user_id', $davide->getKey())->count())->toBe(1);

    // 5. Revoca il consenso: la data sparisce e la newsletter già programmata non parte.
    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', ['marketing_opt_in' => false])
        ->assertOk()
        ->assertJsonPath('data.marketing_opt_in', false);

    expect($davide->fresh()?->marketing_opt_in_at)->toBeNull();

    Notification::fake();

    $newsletter = ScheduledNotification::query()->ofType(NotificationType::WeekendNewsletter->value)->sole();
    Carbon::setTestNow($newsletter->send_at);
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNotSentTo($davide, ScheduledMessage::class, fn (ScheduledMessage $n): bool => $n->message->type === NotificationType::WeekendNewsletter);

    expect($newsletter->fresh()?->status)->toBe(NotificationStatus::Skipped);
});

it('sceglie dall app i locali da seguire e riceve il riepilogo settimanale con le sole novità', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00'); // lunedì
    Notification::fake();

    $seguito = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Teatro Verdi di prova']);
    $altro = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Locale non seguito']);

    // Una categoria seguita senza date in settimana: il riepilogo unisce locali e categorie seguiti.
    $danza = testCategory(['name' => 'Danza', 'slug' => 'danza']);

    $federica = User::factory()->create();
    $token = $federica->createToken('Telefono')->plainTextToken;

    // 1. L'app mostra gli interessi possibili, nessuno scelto.
    $this->withToken($token)->getJson('/api/v1/me/notification-interests')
        ->assertOk()
        ->assertJsonPath('data.venues.0.selected', false)
        ->assertJsonFragment(['name' => 'Teatro Verdi di prova']);

    // Un locale inventato è rifiutato.
    $this->withToken($token)->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => [999999]])
        ->assertUnprocessable();

    // 2. Sceglie il locale e una categoria.
    $risposta = $this->withToken($token)->patchJson('/api/v1/me/notification-interests', [
        'categories' => [$danza->getKey()],
        'venues' => [$seguito->getKey()],
    ])->assertOk();

    $scelti = collect($risposta->json('data.venues'))->where('selected', true)->pluck('name')->all();

    expect($scelti)->toBe(['Teatro Verdi di prova'])
        ->and(collect($risposta->json('data.categories'))->firstWhere('id', $danza->getKey())['selected'])->toBeTrue()
        ->and($federica->follows()->where('followable_type', FollowableType::Venue->value)->pluck('followable_id')->all())->toBe([$seguito->getKey()]);

    // 3. Nella settimana escono date nei due locali; una vecchia del seguito non è una novità.
    $vecchia = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00', event: ['title' => 'Data vecchia'], venue: $seguito);
    $vecchia->event->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();
    $vecchia->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();

    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Novità del Verdi'], venue: $seguito);
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 22:00', event: ['title' => 'Novità altrove'], venue: $altro);

    // 4. Il pianificatore programma il riepilogo del martedì; ripeterlo non lo duplica.
    $this->artisan('notifications:plan')->assertSuccessful();
    $this->artisan('notifications:plan')->assertSuccessful();

    $riepilogo = ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->where('user_id', $federica->getKey())->sole();

    // 5. Martedì alle 18 il riepilogo porta solo la novità del locale seguito.
    Carbon::setTestNow($riepilogo->send_at);
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTo($federica, ScheduledMessage::class, function (ScheduledMessage $n): bool {
        if ($n->message->type !== NotificationType::VenueDigest) {
            return false;
        }

        $titoli = array_column($n->message->items, 'title');

        return $titoli === ['Novità del Verdi'];
    });

    expect($riepilogo->fresh()?->status)->toBe(NotificationStatus::Sent);

    // 6. Toglie il locale: l'interesse sparisce e la settimana dopo niente riepilogo.
    $this->withToken($token)->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => []])
        ->assertOk()
        ->assertJsonPath('data.venues.0.selected', false);

    freezeLocal($this->city, '2026-09-14 12:00');
    $this->artisan('notifications:plan')->assertSuccessful();

    expect(ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->where('user_id', $federica->getKey())->count())->toBe(1);
});
