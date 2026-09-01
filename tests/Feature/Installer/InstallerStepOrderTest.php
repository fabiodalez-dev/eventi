<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Models\City;
use App\Models\User;
use Tests\Support\InstallerSandbox;

/*
 * L'anti-salto (D42, punto 3; D44, punto 2).
 *
 * Non è una gentilezza per l'interfaccia: è la condizione per cui il passo di
 * esecuzione può dare per certo di avere tutti i dati che gli servono. Se si
 * potesse arrivare alla checklist senza aver dichiarato un database, la prima
 * operazione scriverebbe un `.env` con le coordinate del modello — cioè
 * qualcosa che sembra installato e non lo è.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('a mani vuote riporta al primo passo chi chiede', function (string $url): void {
    $this->get($url)->assertRedirect(InstallerStep::Requirements->url());
})->with([
    '/installazione/database',
    '/installazione/applicazione',
    '/installazione/citta',
    '/installazione/amministratore',
    '/installazione/esecuzione',
    '/installazione/fine',
]);

it('si arriva al passo della città solo passando dai primi tre', function (): void {
    /*
     * Un passo alla volta, e a ogni giro si prova a saltare: dopo i requisiti
     * la città rimanda al database, dopo il database rimanda all'applicazione,
     * e solo dopo l'applicazione si apre.
     */
    $this->post('/installazione/requisiti');
    $this->get('/installazione/citta')->assertRedirect(InstallerStep::Database->url());

    $this->post('/installazione/database', testDatabaseCredentials());
    $this->get('/installazione/citta')->assertRedirect(InstallerStep::Application->url());

    $this->post('/installazione/applicazione', [
        'app_name' => 'Prova',
        'app_url' => 'https://eventi.example.test',
        'mail_mailer' => 'log',
    ]);

    $this->get('/installazione/citta')->assertOk();
});

it('risponde prima della validazione, non dopo', function (): void {
    /*
     * È la scoperta di D44, punto 2. Una Form Request valida **prima** che il
     * metodo del controller cominci: un controllo scritto lì arriverebbe dopo,
     * e un POST al passo della città senza database risponderebbe con sette
     * errori di validazione — «la provincia è obbligatoria» — invece del
     * rimando al passo che manca davvero. Chi installa leggerebbe la risposta
     * a una domanda che non ha fatto.
     */
    $this->post('/installazione/citta', ['name' => ''])
        ->assertRedirect(InstallerStep::Requirements->url())
        ->assertSessionHasNoErrors();
});

it('non lascia eseguire niente a chi salta direttamente alla checklist', function (): void {
    $this->post('/installazione/esecuzione')
        ->assertRedirect(InstallerStep::Requirements->url());

    expect(is_file($this->sandbox->envPath))->toBeFalse()
        ->and(City::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('lascia tornare indietro su un passo già completato e ricorda le risposte', function (): void {
    /*
     * L'anti-salto guarda avanti, non indietro: correggere l'indirizzo del
     * sito dopo aver compilato la città deve essere possibile, o l'unico
     * rimedio a un refuso sarebbe ricominciare.
     */
    completeThroughAdmin();

    $this->get('/installazione/applicazione')
        ->assertOk()
        ->assertSee('value="https://eventi.example.test"', false);
});

it('porta al primo passo incompleto chi apre /installazione a metà strada', function (): void {
    $this->post('/installazione/requisiti');
    $this->post('/installazione/database', testDatabaseCredentials());

    $this->get('/installazione')->assertRedirect(InstallerStep::Application->url());
});

it('legge il passo dall’indirizzo, che è l’unica cosa che hanno anche i POST', function (): void {
    /*
     * Dal nome della rotta non si potrebbe: i POST del wizard non ne hanno uno.
     * Il valore dell'enum **è** il secondo segmento dell'indirizzo, ed è ciò
     * che tiene insieme le due cose senza una tabella da allineare a mano.
     */
    foreach (InstallerStep::cases() as $step) {
        expect($step->url())->toEndWith('/installazione/'.$step->value);
    }
});

it('dichiara i passi che ciascuno pretende già completati', function (): void {
    /*
     * L'ordine di dichiarazione dell'enum è ciò su cui si regge tutto il
     * controllo: spostare un caso cambierebbe in silenzio il significato di
     * `previous()` e riaprirebbe il salto.
     */
    expect(InstallerStep::Requirements->previous())->toBe([])
        ->and(InstallerStep::City->previous())->toBe([
            InstallerStep::Requirements,
            InstallerStep::Database,
            InstallerStep::Application,
        ])
        ->and(InstallerStep::Done->previous())->toHaveCount(6);
});
