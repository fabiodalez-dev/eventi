<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Enums\VenueRole;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\NotificationScheduler;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `App\Services\Notifications\NotificationScheduler` chiamato direttamente.
 *
 * Gli altri test delle notifiche entrano dal gesto — si salva una data, si
 * pubblica un evento — e verificano che alla fine parta la posta giusta. Qui
 * si verifica il contratto del pezzo in mezzo: che cosa restituisce, quante
 * righe scrive, e soprattutto quante **non** ne scrive quando gli si chiede
 * due volte la stessa cosa. La `dedupe_key` è, senza Redis (D5), l'unica
 * difesa contro il doppio invio: qui la si prende a martellate.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->scheduler = app(NotificationScheduler::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Chi ha salvato la data: è la condizione di ogni avviso di §15.4. */
function utenteCheHaSalvato(EventOccurrence $occurrence): User
{
    $user = User::factory()->create();

    SavedEvent::factory()->create([
        'user_id' => $user->getKey(),
        'occurrence_id' => $occurrence->getKey(),
    ]);

    return $user;
}

it('non tocca il database quando non c\'è nessuno da avvisare', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');

    DB::enableQueryLog();

    expect($this->scheduler->remindersFor($occurrence, []))->toBe(0)
        ->and($this->scheduler->remindersForMany([$occurrence], []))->toBe(0);

    /* Un elenco vuoto esce prima di leggere gli utenti: la migrazione dei
       salvataggi di un anonimo che non ne aveva chiama proprio così. */
    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('legge le preferenze di chi riceve una volta sola, non una per data', function (): void {
    $occurrences = [];

    foreach (range(10, 14) as $giorno) {
        $occurrences[] = occurrenceAtLocal($this->city, $this->category, '2026-09-'.$giorno.' 21:00');
    }

    $primo = User::factory()->create();
    $secondo = User::factory()->create();

    DB::enableQueryLog();

    $scritte = $this->scheduler->remindersForMany($occurrences, [
        (int) $primo->getKey(),
        (int) $secondo->getKey(),
    ]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Cinque date × due persone × due promemoria.
    expect($scritte)->toBe(20)
        /* Una lettura degli utenti e una scrittura sola: se un giorno
           qualcuno rimettesse la lettura dentro il ciclo, decine di
           salvataggi migrati diventerebbero decine di query. */
        ->and($queries)->toHaveCount(2);
});

it('non moltiplica i promemoria quando lo stesso identificativo arriva due volte', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
    $user = User::factory()->create();

    $id = (int) $user->getKey();

    expect($this->scheduler->remindersFor($occurrence, [$id, $id, $id]))->toBe(2)
        ->and(ScheduledNotification::query()->count())->toBe(2);
});

it('non promette un promemoria per una serata già annullata', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', occurrence: [
        'status' => OccurrenceStatus::Cancelled,
    ]);

    $user = User::factory()->create();

    expect($this->scheduler->remindersFor($occurrence, [(int) $user->getKey()]))->toBe(0);
});

it('avvisa dell\'esaurito solo chi ha quella data in agenda', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');

    $destinatario = utenteCheHaSalvato($occurrence);
    $estraneo = User::factory()->create();

    expect($this->scheduler->announceSoldOut($occurrence))->toBe(1);

    $rows = ScheduledNotification::query()->ofType(NotificationType::EventSoldOut->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->user_id)->toBe($destinatario->getKey())
        ->and($rows->pluck('user_id')->all())->not->toContain($estraneo->getKey())
        ->and($rows->first()?->dedupe_key)->toBe(
            sprintf('sold_out:user_%d:occ_%d', $destinatario->getKey(), $occurrence->getKey()),
        );

    // Dichiararlo esaurito una seconda volta non è una seconda notizia.
    expect($this->scheduler->announceSoldOut($occurrence))->toBe(0)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventSoldOut->value)->count())->toBe(1);
});

it('non avvisa dell\'esaurito una serata già cominciata', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-08-20 21:00');

    utenteCheHaSalvato($occurrence);

    expect($this->scheduler->announceSoldOut($occurrence))->toBe(0)
        ->and($this->scheduler->announceMove($occurrence, null))->toBe(0);
});

it('scrive nell\'avviso di spostamento da dove la data si è mossa, e accetta di non saperlo', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
    utenteCheHaSalvato($occurrence);

    expect($this->scheduler->announceMove($occurrence, null))->toBe(1);

    $senzaOrigine = ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)->firstOrFail();

    expect($senzaOrigine->context('previous_starts_at'))->toBeNull()
        ->and($senzaOrigine->context('starts_at'))->toBe($occurrence->starts_at->utc()->toIso8601String());

    $altra = occurrenceAtLocal($this->city, $this->category, '2026-09-21 21:00');
    utenteCheHaSalvato($altra);

    $prima = localInstant($this->city, '2026-09-21 18:00');

    expect($this->scheduler->announceMove($altra, $prima))->toBe(1);

    $conOrigine = ScheduledNotification::query()
        ->ofType(NotificationType::EventMoved->value)
        ->where('notifiable_id', $altra->getKey())
        ->firstOrFail();

    expect($conOrigine->context('previous_starts_at'))->toBe($prima->utc()->toIso8601String());
});

it('conta due spostamenti come due notizie, e lo stesso spostamento come una', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
    utenteCheHaSalvato($occurrence);

    expect($this->scheduler->announceMove($occurrence, null))->toBe(1)
        // Stessa data d'arrivo: la chiave porta l'istante, quindi è la stessa notizia.
        ->and($this->scheduler->announceMove($occurrence, null))->toBe(0);

    $occurrence->starts_at = localInstant($this->city, '2026-09-20 22:30');
    $occurrence->save();

    /* Il salvataggio passa dall'observer, che annuncia il nuovo orario: la
       chiave porta l'istante d'arrivo, quindi è una riga in più e non un
       doppione della prima. Richiederlo ancora non aggiunge nulla. */
    expect(ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)->count())->toBe(2)
        ->and($this->scheduler->announceMove($occurrence->fresh(), null))->toBe(0)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventMoved->value)
            ->pluck('dedupe_key')->unique()->count())->toBe(2);
});

it('non avvisa nessuno di uno spostamento che nessuno aspettava', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');

    expect($this->scheduler->announceMove($occurrence, null))->toBe(0)
        ->and($this->scheduler->announceSoldOut($occurrence))->toBe(0)
        ->and(ScheduledNotification::query()->count())->toBe(0);
});

it('annulla comunque i promemoria di una serata che nessuno aveva salvato', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');

    /* Una riga in attesa può esistere senza salvataggio: chi ha tolto la data
       dall'agenda dopo che il promemoria era già nato. */
    $user = User::factory()->create();
    $this->scheduler->remindersFor($occurrence, [(int) $user->getKey()]);

    expect($this->scheduler->announceCancellation($occurrence))->toBe(0)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventReminder->value)->get()
            ->pluck('status')->unique()->all())
        ->toBe([NotificationStatus::Cancelled]);
});

it('annulla tutto tranne il tipo che gli si dice di risparmiare', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
    $user = utenteCheHaSalvato($occurrence);

    $this->scheduler->remindersFor($occurrence, [(int) $user->getKey()]);
    $this->scheduler->announceSoldOut($occurrence);

    $annullate = $this->scheduler->cancelPending($occurrence, NotificationType::EventSoldOut);

    expect($annullate)->toBe(2)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventSoldOut->value)->firstOrFail()->status)
        ->toBe(NotificationStatus::Pending)
        ->and(ScheduledNotification::query()->pending()->count())->toBe(1);
});

it('avvisa referente e collaboratori del locale, non solo chi ha scritto la scheda', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $referente = User::factory()->owning($venue)->create();
    $collaboratore = User::factory()->create();
    $venue->members()->attach($collaboratore->getKey(), [
        'role' => VenueRole::Editor->value,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', venue: $venue)->event;

    expect($this->scheduler->announceEventPublished($event))->toBe(2);

    $destinatari = ScheduledNotification::query()
        ->ofType(NotificationType::EventPublished->value)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($destinatari)->toBe(collect([$referente->getKey(), $collaboratore->getKey()])->sort()->values()->all());
});

it('non ha nessuno da avvisare quando l\'evento non ha un locale', function (): void {
    $event = Event::factory()->published()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => null,
        'custom_location' => ['name' => 'Prato della Valle'],
    ]);

    expect($this->scheduler->announceEventPublished($event))->toBe(0)
        ->and($this->scheduler->announceEventRejected($event))->toBe(0)
        ->and(ScheduledNotification::query()->count())->toBe(0);
});

it('ripete il rifiuto il giorno dopo, ma non due volte nello stesso giorno', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    User::factory()->owning($venue)->create();

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', venue: $venue)->event;

    expect($this->scheduler->announceEventRejected($event))->toBe(1)
        ->and($this->scheduler->announceEventRejected($event))->toBe(0);

    /* Un secondo rifiuto in un altro giorno è un'altra decisione, con un'altra
       motivazione: chi gestisce il locale deve saperlo. */
    freezeLocal($this->city, '2026-09-02 12:00');

    expect($this->scheduler->announceEventRejected($event))->toBe(1)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventRejected->value)->count())->toBe(2);
});

it('dice di non aver accodato niente quando la chiave esiste già', function (): void {
    $user = User::factory()->create();
    $quando = CarbonImmutable::now()->addDay();

    expect($this->scheduler->queue(
        userId: (int) $user->getKey(),
        type: NotificationType::DailyDigest,
        dedupeKey: 'daily:user_'.$user->getKey().':2026-09-02',
        sendAt: $quando,
    ))->toBeTrue();

    expect($this->scheduler->queue(
        userId: (int) $user->getKey(),
        type: NotificationType::DailyDigest,
        dedupeKey: 'daily:user_'.$user->getKey().':2026-09-02',
        sendAt: $quando,
    ))->toBeFalse();

    $row = ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->firstOrFail();

    expect(ScheduledNotification::query()->count())->toBe(1)
        /* Un invio che non riguarda una data non ha soggetto: la colonna
           morph resta vuota invece di puntare al nulla. */
        ->and($row->notifiable_type)->toBeNull()
        ->and($row->notifiable_id)->toBeNull()
        ->and($row->payload)->toBeNull();
});

it('riprogramma solo ciò che è ancora in attesa, e lascia stare il resto', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
    $user = User::factory()->create();

    $this->scheduler->remindersFor($occurrence, [(int) $user->getKey()]);

    $giaMandato = ScheduledNotification::query()->orderBy('send_at')->firstOrFail();
    $giaMandato->markSent(CarbonImmutable::now());

    $occurrence->starts_at = localInstant($this->city, '2026-09-20 23:00');
    $occurrence->save();

    expect($this->scheduler->reschedule($occurrence->fresh()))->toBe(1)
        ->and($giaMandato->fresh()?->status)->toBe(NotificationStatus::Sent);
});
