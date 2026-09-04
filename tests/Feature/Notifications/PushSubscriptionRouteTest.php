<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use App\Support\Notifications\PreferenceLinks;

/**
 * L'iscrizione del browser al canale push (§15.6, D54): chi può farla, cosa
 * scrive in `devices` e cosa mostra la pagina delle preferenze.
 */
beforeEach(function (): void {
    testCity();

    $this->user = User::factory()->create();

    config()->set('webpush.vapid.public_key', 'chiave-pubblica-di-prova');
    config()->set('webpush.vapid.private_key', 'chiave-privata-di-prova');
});

/**
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
 */
function iscrizioneDiProva(string $endpoint = 'https://push.example.org/uno'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'chiave-del-browser', 'auth' => 'segreto-del-browser'],
    ];
}

it('registra il browser come dispositivo web', function (): void {
    $this->actingAs($this->user)
        ->postJson(route('account.push.store'), iscrizioneDiProva())
        ->assertCreated();

    $device = Device::query()->firstOrFail();

    expect($device->user_id)->toBe($this->user->getKey())
        ->and($device->platform)->toBe(DevicePlatform::Web)
        ->and($device->endpoint)->toBe('https://push.example.org/uno')
        ->and($device->keys)->toBe(['p256dh' => 'chiave-del-browser', 'auth' => 'segreto-del-browser'])
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->revoked_at)->toBeNull();
});

it('aggiorna la riga invece di crearne una seconda, e rinfresca l ultimo accesso', function (): void {
    /*
     * È la deduplica di `SCHEMA.md` §3.14, e insieme il rinnovo che dà senso
     * alla finestra di trenta giorni: la pagina rimanda l'iscrizione a ogni
     * visita, e senza questo aggiornamento `last_seen_at` resterebbe la data
     * della prima volta.
     */
    $vecchio = Device::factory()->for($this->user)->create([
        'endpoint' => 'https://push.example.org/uno',
        'last_seen_at' => now()->subMonths(3),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('account.push.store'), iscrizioneDiProva())
        ->assertCreated();

    expect(Device::query()->count())->toBe(1)
        ->and($vecchio->refresh()->last_seen_at?->isToday())->toBeTrue();
});

it('stacca lo stesso browser da chi lo usava prima', function (): void {
    // Un browser è unico per installazione, non per account: se l'iscrizione
    // resta appesa al precedente, quello continua a ricevere sullo schermo di
    // chi si è collegato dopo.
    $altro = User::factory()->create();

    $device = Device::factory()->for($altro)->create([
        'endpoint' => 'https://push.example.org/uno',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('account.push.store'), iscrizioneDiProva())
        ->assertCreated();

    expect($device->refresh()->revoked_at)->not->toBeNull()
        ->and($this->user->devices()->whereNull('revoked_at')->count())->toBe(1);
});

it('revoca senza cancellare', function (): void {
    $device = Device::factory()->for($this->user)->create([
        'endpoint' => 'https://push.example.org/uno',
    ]);

    $this->actingAs($this->user)
        ->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://push.example.org/uno'])
        ->assertOk();

    expect($device->refresh()->revoked_at)->not->toBeNull()
        ->and(Device::query()->count())->toBe(1);
});

it('senza endpoint revoca tutti i browser di chi lo chiede', function (): void {
    // È il caso di chi ha tolto il permesso dalle impostazioni del browser:
    // l'iscrizione da citare non esiste più, la riga in `devices` sì.
    $device = Device::factory()->for($this->user)->create();
    $telefono = Device::factory()->for($this->user)->mobile()->create();

    $this->actingAs($this->user)
        ->deleteJson(route('account.push.destroy'))
        ->assertOk();

    expect($device->refresh()->revoked_at)->not->toBeNull()
        // Il token di un'app non c'entra con il permesso di un browser.
        ->and($telefono->refresh()->revoked_at)->toBeNull();
});

it('non lascia iscrivere chi non ha fatto l accesso', function (): void {
    $this->postJson(route('account.push.store'), iscrizioneDiProva())
        ->assertUnauthorized();

    expect(Device::query()->count())->toBe(0);
});

it('rifiuta un iscrizione senza le chiavi del browser', function (): void {
    // Una riga senza chiavi il canale la prenderebbe e non potrebbe cifrarla:
    // l'errore comparirebbe settimane dopo, dentro un job in coda.
    $this->actingAs($this->user)
        ->postJson(route('account.push.store'), ['endpoint' => 'https://push.example.org/uno'])
        ->assertJsonValidationErrors(['keys']);

    expect(Device::query()->count())->toBe(0);
});

it('mostra l interruttore solo a chi ha una sessione su questo account', function (): void {
    $url = PreferenceLinks::preferences($this->user);

    /*
     * Il collegamento firmato arriva per email e può essere inoltrato: finora
     * permetteva solo di far smettere le notifiche. Accendere un canale che le
     * dirotta su uno schermo è il gesto opposto, e vuole una sessione.
     */
    $this->get($url)
        ->assertOk()
        ->assertDontSee('data-push-toggle', escape: false)
        ->assertSee(__('notifications.push.signed_out'));

    $this->actingAs($this->user)
        ->get($url)
        ->assertOk()
        ->assertSee('data-push-toggle', escape: false)
        ->assertSee(__('notifications.push.title'));
});

it('non mostra l interruttore senza chiavi VAPID', function (): void {
    // Un interruttore che il server ignora è peggio di nessun interruttore:
    // è la regola che la pagina già applica agli avvisi obbligatori.
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);

    $this->actingAs($this->user)
        ->get(PreferenceLinks::preferences($this->user))
        ->assertOk()
        ->assertDontSee('data-push-toggle', escape: false);
});
