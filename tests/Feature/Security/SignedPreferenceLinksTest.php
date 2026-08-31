<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\User;
use App\Support\Notifications\PreferenceLinks;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * §15.9: «pagina preferenze raggiungibile senza login tramite token firmato».
 *
 * Senza login la firma **è** l'autenticazione, quindi è l'unica cosa che
 * separa «spengo le mie notifiche» da «spengo quelle di chiunque, provando gli
 * identificativi uno per uno». I test qui sotto attaccano quella firma nei
 * quattro modi in cui la si attacca davvero: cambiando l'identificativo,
 * riscrivendo la firma, aspettando la scadenza, e togliendola del tutto.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

    $this->io = User::factory()->create(['email' => 'io@example.test']);
    $this->altro = User::factory()->create(['email' => 'altro@example.test']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('apre la pagina delle preferenze a chi porta un collegamento valido', function (): void {
    $this->get(PreferenceLinks::preferences($this->io))
        ->assertOk()
        ->assertSee(__('notifications.preferences.title'), escape: false);
});

/**
 * L'attacco più ovvio: prendo il **mio** collegamento e ci scrivo dentro
 * l'identificativo di un altro.
 */
it('rifiuta il collegamento di una persona riscritto con l identificativo di un altra', function (): void {
    $mio = PreferenceLinks::preferences($this->io);

    $manomesso = str_replace(
        '/notifiche/preferenze/'.$this->io->getKey(),
        '/notifiche/preferenze/'.$this->altro->getKey(),
        $mio,
    );

    expect($manomesso)->not->toBe($mio);

    $this->get($manomesso)->assertForbidden();
});

it('rifiuta una firma riscritta a mano', function (): void {
    $link = PreferenceLinks::preferences($this->io);
    $rotto = preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('a', 64), $link);

    $this->get((string) $rotto)->assertForbidden();
});

it('rifiuta un collegamento senza firma', function (): void {
    $this->get('/notifiche/preferenze/'.$this->io->getKey())->assertForbidden();
});

it('rifiuta un collegamento scaduto', function (): void {
    $link = PreferenceLinks::preferences($this->io);

    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00')
        ->addDays(config()->integer('notifications.preference_link_days') + 1));

    $this->get($link)->assertForbidden();
});

/**
 * La scadenza è dentro la firma, quindi non si sposta in avanti riscrivendola:
 * è il tentativo che un attaccante fa subito dopo essersi accorto della
 * scadenza.
 */
it('non si fa allungare la scadenza riscrivendola nell indirizzo', function (): void {
    $link = PreferenceLinks::preferences($this->io);

    $prorogato = preg_replace(
        '/expires=\d+/',
        'expires='.Carbon::parse('2027-01-01 00:00:00')->getTimestamp(),
        $link,
    );

    Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));

    $this->get((string) $prorogato)->assertForbidden();
});

it('rifiuta il salvataggio delle preferenze senza firma', function (): void {
    $this->patch('/notifiche/preferenze/'.$this->io->getKey(), [
        'reminders' => '1',
    ])->assertForbidden();

    expect($this->io->fresh()?->notification_preferences)->toBeNull();
});

/**
 * Salvare con una firma valida cambia **solo** le preferenze di chi la firma
 * nomina: nessun campo del corpo può spostare la modifica su un altro account.
 */
it('non lascia spostare il salvataggio su un altro account con un campo del corpo', function (): void {
    $this->patch(PreferenceLinks::preferences($this->io), [
        'user' => $this->altro->getKey(),
        'user_id' => $this->altro->getKey(),
        'reminders' => '0',
        'sold_out' => '0',
        'venue_digest' => '0',
        'daily_digest' => '0',
    ])->assertRedirect();

    expect($this->io->fresh()?->notificationPreferences()->reminders)->toBeFalse()
        ->and($this->altro->fresh()?->notificationPreferences()->reminders)->toBeTrue();
});

/**
 * La disiscrizione a un click ha la stessa firma e la stessa fragilità: un
 * identificativo riscritto non deve spegnere le notifiche di nessuno.
 */
it('rifiuta una disiscrizione riscritta per un altro account', function (): void {
    $link = PreferenceLinks::unsubscribe($this->io, NotificationType::EventReminder);

    $manomesso = str_replace(
        '/notifiche/disiscriviti/'.$this->io->getKey().'/',
        '/notifiche/disiscriviti/'.$this->altro->getKey().'/',
        (string) $link,
    );

    $this->get($manomesso)->assertForbidden();

    expect($this->altro->fresh()?->notificationPreferences()->reminders)->toBeTrue();
});

/**
 * Il collegamento è firmato con la chiave dell'applicazione: rigenerarla — la
 * rotazione di un segreto, in `RUNBOOK.md` — invalida quelli già spediti,
 * che è esattamente ciò che ci si aspetta da un segreto ruotato.
 */
it('smette di valere se la chiave dell applicazione cambia', function (): void {
    $link = PreferenceLinks::preferences($this->io);

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    URL::clearResolvedInstances();

    $this->get($link)->assertForbidden();
});
