<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Models\Device;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * §15.9: «Cancellazione account: rimozione di salvataggi, follow, device e
 * notifiche programmate; conservazione anonimizzata solo di ciò che è
 * legalmente necessario.»
 *
 * Le due domande a cui questi test rispondono sono diverse fra loro:
 *
 * 1. **è sparito ciò che doveva sparire?** — e sparire vuol dire anche non
 *    partire: un promemoria ancora in coda il giorno dopo è la prova che la
 *    cancellazione non ha funzionato, e arriva a un indirizzo che ha chiesto
 *    di essere dimenticato;
 * 2. **è rimasto ciò che doveva rimanere?** — i dati di *chiunque altro*. Una
 *    cancellazione scritta con una `where` in meno cancella l'archivio di
 *    tutti, e nessun test che guardi solo chi se ne va se ne accorge.
 */
/**
 * Una notifica qualunque nell'archivio in-app di §15.6: serve solo a lasciare
 * una riga in `notifications` da cui la cancellazione debba passare.
 */
final class ArchivioInApp extends Notification
{
    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(mixed $notifiable): array
    {
        return ['messaggio' => 'archivio in-app'];
    }
}

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Una persona con tutto ciò che un account può accumulare.
 */
function utenteConTuttiIDati(EventOccurrence $occurrence, string $email): User
{
    $user = User::factory()->create(['email' => $email, 'name' => 'Chi c\'era']);

    /* Il salvataggio passa dall'azione vera: è quella che, oltre alla riga in
       `saved_events`, mette in agenda i due promemoria di §15.4. Fabbricarli a
       mano proverebbe la cancellazione di righe inventate da questo test. */
    app(SaveOccurrences::class)->one($user, $occurrence);

    Follow::factory()->create(['user_id' => $user->getKey()]);
    Device::factory()->create(['user_id' => $user->getKey()]);
    NotificationLog::factory()->create(['user_id' => $user->getKey()]);

    $user->notify(new ArchivioInApp);

    return $user;
}

it('toglie salvataggi, follow, dispositivi e archivio in-app', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'via@example.test');

    expect($user->notifications()->count())->toBe(1);

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    expect($user->savedEvents()->count())->toBe(0)
        ->and($user->follows()->count())->toBe(0)
        ->and($user->devices()->count())->toBe(0)
        ->and($user->notifications()->count())->toBe(0);
});

it('spegne gli invii previsti invece di lasciarli in attesa di un destinatario', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'niente-piu@example.test');

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    expect(ScheduledNotification::query()
        ->where('user_id', $user->getKey())
        ->where('status', NotificationStatus::Pending->value)
        ->count())->toBe(0);
});

/**
 * La prova che conta non è lo stato della riga: è che il worker, girando dopo
 * la cancellazione, **non spedisce niente**.
 */
it('non fa partire nessun messaggio dopo la cancellazione, nemmeno al primo giro del worker', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'silenzio@example.test');

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    NotificationFacade::fake();

    /* L'ora del promemoria a 3h: senza spostarsi qui il worker non prenderebbe
       in mano nulla, e il test passerebbe per il motivo sbagliato. */
    Carbon::setTestNow(localInstant($this->city, '2026-09-12 18:30'));

    app(NotificationDispatcher::class)->run();

    NotificationFacade::assertNothingSent();
});

it('anonimizza la riga che resta: niente nome, niente indirizzo consegnabile', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'nome.cognome@example.test');

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    $user->refresh();

    expect($user->trashed())->toBeTrue()
        ->and($user->name)->toBeNull()
        ->and($user->email)->not->toContain('nome.cognome')
        ->and($user->email)->toEndWith('.invalid')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->quiet_hours)->toBeNull()
        ->and($user->notification_preferences)->toBeNull();
});

/**
 * La verifica speculare: chi non se ne va non perde niente. È il test che
 * fallisce se una `where('user_id', ...)` viene dimenticata.
 */
it('non tocca i dati di nessun altro', function (): void {
    $chiVa = utenteConTuttiIDati($this->occurrence, 'va@example.test');
    $chiResta = utenteConTuttiIDati($this->occurrence, 'resta@example.test');

    $this->actingAs($chiVa)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    expect($chiResta->fresh()?->trashed())->toBeFalse()
        ->and($chiResta->savedEvents()->count())->toBe(1)
        ->and($chiResta->follows()->count())->toBe(1)
        ->and($chiResta->devices()->count())->toBe(1)
        ->and($chiResta->notifications()->count())->toBe(1)
        ->and(ScheduledNotification::query()
            ->where('user_id', $chiResta->getKey())
            ->where('status', NotificationStatus::Pending->value)
            ->count())->toBe(2);
});

/**
 * Cancellare un account non cancella il programma della città: l'occorrenza
 * salvata resta pubblicata, perché non era un dato di quella persona.
 */
it('lascia in piedi gli eventi che la persona aveva salvato', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'addio@example.test');

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    $this->get('/eventi/'.$this->occurrence->event->slug)->assertOk();
});

/**
 * L'app cancella dall'API (§15.8, `DELETE /v1/me`): stesso effetto, e il token
 * con cui è arrivata la richiesta muore nello stesso istante.
 */
it('cancella anche dall API, e il token smette di valere subito', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'app@example.test');
    $token = $user->createToken('Telefono')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/me', [
        'confirmation' => 'CANCELLA',
        'password' => 'password',
    ])->assertOk();

    expect($user->fresh()?->trashed())->toBeTrue();

    /* La chiamata successiva è di un'altra richiesta HTTP, e in una richiesta
       nuova il guard non ha ancora nessuno in mano. Nella suite il container è
       lo stesso fra una `getJson` e l'altra, e il guard di Sanctum tiene in
       cache chi ha già riconosciuto: senza questa riga il test proverebbe che
       la memoria del guard è sopravvissuta, non che il token è morto. */
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('non accetta la cancellazione da chi non è entrato', function (): void {
    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('login'));

    $this->deleteJson('/api/v1/me')->assertUnauthorized();
});

/**
 * Il messaggio che era già stato composto per chi se n'è andato non riparte:
 * `ScheduledMessage` è la notifica in coda del motore di §15.5.
 */
it('non recapita a un account cancellato nemmeno una riga rimasta in sospeso', function (): void {
    $user = utenteConTuttiIDati($this->occurrence, 'sospeso@example.test');

    /* Una riga che sfugge alla cancellazione: la si rimette a mano in attesa,
       che è la situazione peggiore possibile — un dato scritto da un'altra
       parte del sistema dopo la cancellazione. */
    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    ScheduledNotification::query()
        ->where('user_id', $user->getKey())
        ->update(['status' => NotificationStatus::Pending->value]);

    NotificationFacade::fake();

    Carbon::setTestNow(localInstant($this->city, '2026-09-12 18:30'));

    app(NotificationDispatcher::class)->run();

    NotificationFacade::assertNotSentTo([$user], ScheduledMessage::class);

    expect(ScheduledNotification::query()
        ->where('user_id', $user->getKey())
        ->where('status', NotificationStatus::Pending->value)
        ->count())->toBe(0)
        ->and(ScheduledNotification::query()
            ->where('user_id', $user->getKey())
            ->where('last_error', 'account_deleted')
            ->count())->toBeGreaterThan(0);
});
