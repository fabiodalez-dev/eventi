<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Session;

/**
 * §16: «CSRF» fra le protezioni obbligatorie.
 *
 * Il controllo non si vede mai nella suite, e per un motivo preciso:
 * `PreventRequestForgery` si disattiva da solo quando l'applicazione sa di
 * stare girando dentro dei test. È una comodità che ha un prezzo — nessuno dei
 * ~950 test esistenti tocca il gettone, quindi togliere il middleware da un
 * gruppo di rotte non farebbe fallire niente. Qui il travestimento viene
 * tolto: si dichiara al container un ambiente diverso da `testing`, e da quel
 * momento il middleware si comporta come in produzione.
 *
 * Le tre condizioni che lo fanno passare sono `Sec-Fetch-Site: same-origin`,
 * un `_token` che coincide con quello di sessione, o un `X-CSRF-TOKEN`
 * uguale. I test qui sotto verificano che ne serva **almeno una**, e che
 * nessuna richiesta arrivi al controller senza.
 */
function conCsrfAttivo(Closure $prova): void
{
    /*
     * Il middleware chiede `$app['env'] === 'testing'` per farsi da parte:
     * sostituire quella voce nel container è il solo modo di provarlo senza
     * riscrivere il kernel. Si rimette com'era subito dopo, qualunque cosa
     * succeda.
     */
    $precedente = app('env');
    app()->instance('env', 'local');

    try {
        $prova();
    } finally {
        app()->instance('env', $precedente);
    }
}

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    Category::factory()->create(['default_duration_minutes' => 120]);
});

it('rifiuta una proposta di evento senza gettone', function (): void {
    conCsrfAttivo(function (): void {
        $this->withoutExceptionHandling()
            ->post('/proponi-evento', [
                'title' => 'Concerto senza gettone',
                'email' => 'chi@example.test',
                'starts_at' => '2026-10-01 21:00',
            ]);
    });
})->throws(TokenMismatchException::class);

it('rifiuta una richiesta di accreditamento senza gettone', function (): void {
    conCsrfAttivo(function (): void {
        $this->withoutExceptionHandling()
            ->post('/registra-il-tuo-locale', [
                'venue_name' => 'Locale senza gettone',
                'contact_email' => 'chi@example.test',
            ]);
    });
})->throws(TokenMismatchException::class);

it('rifiuta una registrazione senza gettone', function (): void {
    conCsrfAttivo(function (): void {
        $this->withoutExceptionHandling()
            ->post('/registrati', [
                'email' => 'senza-gettone@example.test',
                'password' => 'una-password-lunga',
                'password_confirmation' => 'una-password-lunga',
            ]);
    });

    expect(User::query()->where('email', 'senza-gettone@example.test')->exists())->toBeFalse();
})->throws(TokenMismatchException::class);

it('rifiuta un accesso senza gettone', function (): void {
    conCsrfAttivo(function (): void {
        $this->withoutExceptionHandling()
            ->post('/accedi', [
                'email' => 'chi@example.test',
                'password' => 'una-password-lunga',
            ]);
    });
})->throws(TokenMismatchException::class);

/**
 * Senza gettone la richiesta non arriva nemmeno alla validazione: se
 * arrivasse, la risposta sarebbe un 422 con gli errori del modulo, e vorrebbe
 * dire che il controller ha già visto i dati.
 */
it('non lascia arrivare al controller una richiesta senza gettone', function (): void {
    conCsrfAttivo(function (): void {
        $risposta = $this->post('/proponi-evento', []);

        $risposta->assertStatus(419);
        $risposta->assertSessionHasNoErrors();
    });
});

it('accetta la stessa proposta quando il gettone di sessione c è', function (): void {
    conCsrfAttivo(function (): void {
        Session::start();

        $this->post('/proponi-evento', [
            '_token' => Session::token(),
            'title' => 'Concerto con gettone',
            'email' => 'chi@example.test',
            'description' => 'Una serata di prova con un testo abbastanza lungo per il modulo.',
            'starts_at' => '2026-10-01 21:00',
            'category_id' => Category::query()->value('id'),
        ])->assertRedirect();
    });
});

/**
 * Il browser che invia dalla stessa origine dichiara `Sec-Fetch-Site` e passa
 * senza gettone: è il percorso che rende inutile propagare il gettone alle
 * chiamate `fetch` del sito. Se un giorno smettesse di valere, i moduli
 * dinamici si romperebbero in silenzio.
 */
it('accetta una richiesta che dichiara la stessa origine', function (): void {
    conCsrfAttivo(function (): void {
        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post('/proponi-evento', [])
            ->assertStatus(302);
    });
});

/**
 * `Sec-Fetch-Site: cross-site` è ciò che il browser scrive quando il modulo
 * sta su un altro dominio: è **il** caso che la protezione esiste per fermare.
 */
it('rifiuta una richiesta che dichiara di venire da un altro sito', function (): void {
    conCsrfAttivo(function (): void {
        $this->withHeader('Sec-Fetch-Site', 'cross-site')
            ->post('/proponi-evento', [])
            ->assertStatus(419);
    });
});

/**
 * L'API non ha sessione e non ha gettone: si autentica con un token portato
 * nell'intestazione, che è già una prova di intenzione. Un 419 qui vorrebbe
 * dire che l'app mobile non può scrivere niente.
 */
it('non chiede il gettone all API, che si autentica altrimenti', function (): void {
    conCsrfAttivo(function (): void {
        /* 401 e non 419: la richiesta è arrivata al controller, che ha
           risposto «credenziali sbagliate». È la prova che il gettone non è
           stato chiesto. */
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nessuno@example.test',
            'password' => 'sbagliata',
        ])->assertStatus(401);
    });
});
