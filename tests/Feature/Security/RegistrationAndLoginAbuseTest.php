<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Iscrizione e accesso: i due tetti che mancavano.
 *
 * I limitatori `api-auth` e `account-auth` contavano su **indirizzo più
 * email**, e quella chiave lascia aperte le due porte che contano davvero:
 *
 * - **cambiando email** il secchiello è sempre vergine, quindi chi si iscrive
 *   in serie — o riempie la casella di qualcun altro con collegamenti di
 *   accesso e reimpostazioni — non incontrava alcun freno. Ogni chiamata
 *   spedisce un messaggio: su hosting condiviso quella è la reputazione SMTP
 *   del sito;
 * - **cambiando indirizzo** il secchiello è vergine per lo stesso account,
 *   quindi la forza bruta distribuita su un singolo bersaglio otteneva il
 *   tetto moltiplicato per il numero di indirizzi del pool.
 *
 * A queste si aggiunge `outbound-email`, contato sul solo indirizzo, e un
 * tetto per **account** indipendente da chi prova.
 */
beforeEach(function (): void {
    /* L'iscrizione assegna il ruolo `user`: senza il seeder, il test
       fallisce su una cosa che non sta provando. */
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    RateLimiter::clear('invii:127.0.0.1');
    Notification::fake();
});

it('ferma le iscrizioni in serie dall’API anche cambiando email a ogni giro', function (): void {
    $tetto = config()->integer('api.rate_limit.outbound_emails_per_hour');
    $accettate = 0;

    /* Email sempre diversa: è il gesto che azzerava il vecchio limite. */
    for ($i = 0; $i < $tetto + 5; $i++) {
        $risposta = $this->postJson('/api/v1/auth/register', [
            'name' => 'Prova '.$i,
            'email' => "serie{$i}@example.test",
            'password' => 'password-lunga-1',
            'password_confirmation' => 'password-lunga-1',
        ]);

        if ($risposta->status() !== 429) {
            $accettate++;
        }
    }

    expect($accettate)->toBeLessThanOrEqual($tetto)
        ->and(User::query()->where('email', 'like', 'serie%@example.test')->count())->toBeLessThanOrEqual($tetto);
});

it('ferma la richiesta in serie di collegamenti di accesso verso caselle altrui', function (): void {
    $tetto = config()->integer('api.rate_limit.outbound_emails_per_hour');
    $accettate = 0;

    for ($i = 0; $i < $tetto + 5; $i++) {
        $risposta = $this->postJson('/api/v1/auth/magic-link', ['code_challenge' => str_repeat('a', 43), 'email' => "vittima{$i}@example.test"]);

        if ($risposta->status() !== 429) {
            $accettate++;
        }
    }

    expect($accettate)->toBeLessThanOrEqual($tetto);
});

it('ferma la forza bruta distribuita su un solo account, da indirizzi diversi', function (): void {
    $vittima = User::factory()->create(['email' => 'bersaglio@example.test']);
    $tetto = config()->integer('api.rate_limit.attempts_per_account');
    $accettati = 0;

    /* Un indirizzo nuovo a ogni tentativo: è ciò che rendeva inutile il
       vecchio limite sulla coppia. */
    for ($i = 0; $i < $tetto + 10; $i++) {
        $risposta = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i % 250)])
            ->postJson('/api/v1/auth/login', [
                'email' => $vittima->email,
                'password' => 'sbagliata-'.$i,
            ]);

        if ($risposta->status() !== 429) {
            $accettati++;
        }
    }

    expect($accettati)->toBeLessThanOrEqual($tetto);
});

/*
 * Il contrappeso, che conta quanto le difese: chi si iscrive davvero deve
 * riuscirci al primo colpo. Una difesa che inciampa sull'uso normale viene
 * allargata fino a non difendere più niente.
 */
it('lascia iscriversi e accedere a chi lo fa una volta', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Persona Vera',
        'email' => 'vera@example.test',
        'password' => 'password-lunga-1',
        'password_confirmation' => 'password-lunga-1',
    ])->assertSuccessful();

    expect(User::query()->where('email', 'vera@example.test')->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'vera@example.test',
        'password' => 'password-lunga-1',
    ])->assertSuccessful();
});

/*
 * Il modulo del sito era già difeso — `throttle:public-forms` vale 5 l'ora per
 * indirizzo, più esca e Turnstile — ed è il confronto che ha fatto notare il
 * buco nell'API. Questa riga tiene ferma quella difesa.
 */
it('tiene il tetto per indirizzo anche sul modulo di iscrizione del sito', function (): void {
    $accettate = 0;

    for ($i = 0; $i < 8; $i++) {
        $risposta = $this->post(route('account.register.store'), [
            'email' => "sito{$i}@example.test",
            'password' => 'password-lunga-1',
            'password_confirmation' => 'password-lunga-1',
        ]);

        if ($risposta->status() !== 429) {
            $accettate++;
        }
    }

    expect($accettate)->toBeLessThanOrEqual(5);
});
