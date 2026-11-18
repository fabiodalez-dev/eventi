<?php

declare(strict_types=1);

use App\DTOs\NotificationPreferences;
use App\Enums\FollowableType;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Support\Features;
use App\Support\Notifications\PreferenceLinks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;
use Symfony\Component\Mime\Email;

/**
 * Le preferenze governate dai collegamenti firmati (§15.9): dalla pagina del
 * sito per chi ha fatto l'accesso, dal piè di pagina di un'email vera per chi
 * non ce l'ha, dal pulsante nativo del client di posta (RFC 8058). E i modi in
 * cui quel collegamento viene manomesso.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return list<Email>
 */
function flussoLinkEmailConsegnate(): array
{
    $email = [];

    foreach (Mail::mailer()->getSymfonyTransport()->messages() as $sent) {
        $original = $sent->getOriginalMessage();

        if ($original instanceof Email) {
            $email[] = $original;
        }
    }

    return $email;
}

it('dal sito apre le proprie preferenze firmate, accende riepilogo e newsletter e il pianificatore li programma', function (): void {
    freezeLocal($this->city, '2026-09-09 12:00'); // mercoledì
    Feature::for(Features::globalScope())->activate(Features::NEWSLETTER);

    $elena = User::factory()->create();

    // 1. Dal menu del sito: /notifiche firma al volo e rimanda alla pagina.
    $redirect = $this->actingAs($elena)->get('/notifiche')->assertRedirect();
    $firmato = (string) $redirect->headers->get('Location');

    expect($firmato)->toContain('/notifiche/preferenze/'.$elena->getKey())
        ->toContain('signature=')
        ->toContain('expires=');

    // 2. La pagina si apre e, con la sessione, offre anche il consenso al marketing.
    $html = $this->actingAs($elena)->get($firmato)
        ->assertOk()
        ->assertSee(__('notifications.preferences.title'))
        ->assertSee(__('account.profile.marketing'))
        ->getContent();

    preg_match('/action="([^"]*notifiche\/preferenze[^"]*)"/', (string) $html, $azione);
    $azione = html_entity_decode($azione[1] ?? '');

    expect($azione)->toContain('signature=');

    // 3. Invia il modulo: accende il riepilogo serale alle 19:30, la newsletter e allarga il silenzio.
    $this->actingAs($elena)->patch($azione, [
        'reminders' => '1',
        'sold_out' => '1',
        'venue_digest' => '1',
        'daily_digest' => '1',
        'daily_digest_time' => '19:30',
        'quiet_from' => '22:00',
        'quiet_to' => '07:00',
        'marketing_opt_in' => '1',
    ])->assertRedirect()->assertSessionHas('status', __('notifications.preferences.saved'));

    $elena->refresh();
    $preferenze = NotificationPreferences::fromUser($elena);

    expect($preferenze->dailyDigest)->toBeTrue()
        ->and($preferenze->reminders)->toBeTrue()
        ->and($elena->daily_digest_time)->toBe('19:30:00')
        ->and($elena->quiet_hours)->toBe(['from' => '22:00', 'to' => '07:00'])
        ->and($elena->marketing_opt_in_at)->not->toBeNull();

    // 4. Il pianificatore orario vede le scelte nuove.
    $this->artisan('notifications:plan')->assertSuccessful();

    $riepilogo = ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->where('user_id', $elena->getKey())->sole();
    $newsletter = ScheduledNotification::query()->ofType(NotificationType::WeekendNewsletter->value)->where('user_id', $elena->getKey())->sole();

    expect($riepilogo->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-09 19:30')
        ->and($newsletter->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-10 16:00');

    // 5. Revoca il consenso dallo stesso modulo: la data del consenso sparisce.
    $this->actingAs($elena)->patch($azione, ['reminders' => '1', 'daily_digest' => '1'])->assertRedirect();

    expect($elena->fresh()?->marketing_opt_in_at)->toBeNull();
});

it('dal piè di pagina di un promemoria vero si disiscrive con un click e il promemoria successivo non parte', function (): void {
    freezeLocal($this->city, '2026-09-01 12:00');

    $paolo = User::factory()->create(['email' => 'paolo@example.test']);
    $token = $paolo->createToken('Telefono')->plainTextToken;

    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $this->withToken($token)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])->assertCreated();

    // Arriva il primo promemoria, per email.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    $email = flussoLinkEmailConsegnate();
    expect($email)->toHaveCount(1);

    $corpo = (string) $email[0]->getHtmlBody();

    preg_match('/href="([^"]*\/notifiche\/disiscriviti\/[^"]*)"/', $corpo, $disiscrizione);
    preg_match('/href="([^"]*\/notifiche\/preferenze\/[^"]*)"/', $corpo, $preferenze);

    $disiscrizione = html_entity_decode($disiscrizione[1] ?? '');

    expect($disiscrizione)->toContain('/notifiche/disiscriviti/'.$paolo->getKey().'/event_reminder')
        ->and($preferenze[1] ?? '')->toContain('signature=');

    // Lo apre dal client di posta: nessuna sessione, nessun token dell'app.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->get($disiscrizione)
        ->assertOk()
        ->assertSee(NotificationType::EventReminder->label())
        ->assertSee('/notifiche/preferenze/'.$paolo->getKey(), escape: false);

    expect(NotificationPreferences::fromUser($paolo->fresh())->reminders)->toBeFalse();

    // Il promemoria di tre ore prima è ancora in coda, ma non parte.
    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    expect(flussoLinkEmailConsegnate())->toHaveCount(1);

    $saltata = ScheduledNotification::query()->ofType('event_reminder')->orderByDesc('send_at')->firstOrFail();

    expect($saltata->status)->toBe(NotificationStatus::Skipped)
        ->and($saltata->last_error)->toBe(NotificationSkipReason::PreferenceOff->value);

    // L'app vede la scelta fatta dall'email (richiesta nuova: il guard di prova non ricorda l'utente di prima).
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.reminders', false);
});

it('segue un locale, riceve il riepilogo settimanale e lo spegne col pulsante nativo del client di posta', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00'); // lunedì

    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Arci Prova']);
    $chiara = User::factory()->create(['email' => 'chiara@example.test']);

    // 1. Dal sito sceglie di seguire il locale fra i propri interessi.
    $this->actingAs($chiara)->get('/notifiche/interessi')->assertOk()->assertSee('Circolo Arci Prova');

    $this->actingAs($chiara)->patch('/notifiche/interessi', ['venues' => [$venue->getKey()], 'categories' => []])
        ->assertRedirect(route('account.notifications.interests'));

    expect($chiara->follows()->where('followable_type', FollowableType::Venue->value)->where('followable_id', $venue->getKey())->value('notify'))->toBeTruthy();

    // 2. Il locale pubblica una data nuova in settimana.
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Concerto del sabato'], venue: $venue);

    // 3. Il pianificatore programma il riepilogo del martedì sera.
    $this->artisan('notifications:plan')->assertSuccessful();

    $riepilogo = ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->where('user_id', $chiara->getKey())->sole();

    expect($riepilogo->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-08 18:00');

    // 4. Martedì alle 18 arriva l'email, con le intestazioni di disiscrizione a un click.
    Carbon::setTestNow($riepilogo->send_at);
    $this->artisan('notifications:send')->assertSuccessful();

    expect($riepilogo->fresh()?->status)->toBe(NotificationStatus::Sent);

    $digest = collect(flussoLinkEmailConsegnate())
        ->first(fn (Email $email): bool => str_contains((string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(), 'venue_digest'));

    expect($digest)->not->toBeNull()
        ->and((string) $digest->getHtmlBody())->toContain('Concerto del sabato')
        ->and($digest->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');

    preg_match('/<([^>]+)>/', (string) $digest->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(), $post);

    // 5. Il client di posta chiama da solo l'indirizzo in POST, senza sessione né browser.
    $this->app['auth']->forgetGuards();
    $this->post($post[1] ?? '')->assertOk();

    expect(NotificationPreferences::fromUser($chiara->fresh())->venueDigest)->toBeFalse();

    // 6. Il lunedì dopo il riepilogo non viene più programmato.
    freezeLocal($this->city, '2026-09-14 12:00');
    $this->artisan('notifications:plan')->assertSuccessful();

    expect(ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->where('user_id', $chiara->getKey())->count())->toBe(1);
});

it('rifiuta i collegamenti manomessi o scaduti e non spegne nulla con quelli che non spengono', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00');

    $io = User::factory()->create();
    $altro = User::factory()->create();

    $preferenze = PreferenceLinks::preferences($io);
    $disiscrizione = (string) PreferenceLinks::unsubscribe($io, NotificationType::EventReminder);

    // Identificativo riscritto: 403, e le notifiche dell'altro restano accese.
    $this->get(str_replace('/disiscriviti/'.$io->getKey().'/', '/disiscriviti/'.$altro->getKey().'/', $disiscrizione))->assertForbidden();
    $this->get(str_replace('/preferenze/'.$io->getKey(), '/preferenze/'.$altro->getKey(), $preferenze))->assertForbidden();

    // Firma riscritta: 403.
    $this->get((string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('b', 64), $disiscrizione))->assertForbidden();

    // Senza firma, né lettura né scrittura.
    $this->get('/notifiche/preferenze/'.$io->getKey())->assertForbidden();
    $this->patch('/notifiche/preferenze/'.$io->getKey(), ['reminders' => '0'])->assertForbidden();
    $this->post('/notifiche/disiscriviti/'.$io->getKey().'/event_reminder')->assertForbidden();

    expect(NotificationPreferences::fromUser($io->fresh())->reminders)->toBeTrue()
        ->and(NotificationPreferences::fromUser($altro->fresh())->reminders)->toBeTrue();

    // Un collegamento firmato per una tipologia obbligatoria risponde, ma non spegne nulla.
    $annullamenti = URL::temporarySignedRoute('notifications.unsubscribe', now()->addDay(), ['user' => $io->getKey(), 'type' => NotificationType::EventCancelled->value]);
    $inventato = URL::temporarySignedRoute('notifications.unsubscribe', now()->addDay(), ['user' => $io->getKey(), 'type' => 'tipologia_inventata']);

    $this->get($annullamenti)->assertOk();
    $this->get($inventato)->assertOk();

    expect($io->fresh()?->notification_preferences)->toBeNull()
        ->and(NotificationPreferences::fromUser($io->fresh())->reminders)->toBeTrue();

    // Trentuno giorni dopo l'invio lo stesso collegamento, prima valido, è scaduto.
    $this->get($preferenze)->assertOk();

    Carbon::setTestNow(Carbon::now()->addDays(config()->integer('notifications.preference_link_days') + 1));

    $this->get($preferenze)->assertForbidden();
    $this->get($disiscrizione)->assertForbidden();

    expect(NotificationPreferences::fromUser($io->fresh())->reminders)->toBeTrue();
});
