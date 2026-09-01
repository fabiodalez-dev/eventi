<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Http\Requests\Installer\AdminStepRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\InstallerSandbox;

/*
 * L'account di amministrazione (D42, punto 3, passo 5).
 *
 * È l'unico utente che esisterà quando l'installazione finisce, e da quel
 * momento è la sola porta d'accesso al pannello: un refuso nell'email o una
 * password che non è quella che si crede di aver scritto significano un sito
 * installato e inaccessibile, senza posta configurata per recuperarlo.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

/** Le quattro risposte del passo, con i soli campi che il caso vuole cambiare. */
function adminAnswers(array $overrides = []): array
{
    return [
        'name' => 'Chi installa',
        'email' => 'admin@example.test',
        'password' => 'password-lunga-1',
        'password_confirmation' => 'password-lunga-1',
        ...$overrides,
    ];
}

/** Porta il wizard fino all'amministratore ed esegue le prime cinque operazioni. */
function installUpToContent(array $adminOverrides = []): void
{
    test()->post('/installazione/requisiti');
    test()->post('/installazione/database', testDatabaseCredentials());
    test()->post('/installazione/applicazione', [
        'app_name' => 'Prova inCittà',
        'app_url' => 'https://eventi.example.test',
        'mail_mailer' => 'log',
    ]);
    test()->post('/installazione/citta', [
        'name' => 'Padova',
        'slug' => '',
        'province_code' => 'pd',
        'province_name' => 'Padova',
        'region' => 'Veneto',
        'timezone' => 'Europe/Rome',
        'center_lat' => '45.4064',
        'center_lng' => '11.8768',
        'radius_km' => '30',
    ]);
    test()->post('/installazione/amministratore', adminAnswers($adminOverrides));

    /* .env, migrazioni, verifica delle tabelle, dati di base, città e amministratore. */
    for ($task = 0; $task < 5; $task++) {
        test()->post('/installazione/esecuzione');
    }
}

it('non manda avanti chi compila male il modulo', function (array $overrides, string $field): void {
    $this->post('/installazione/requisiti');
    $this->post('/installazione/database', testDatabaseCredentials());
    $this->post('/installazione/applicazione', [
        'app_name' => 'Prova',
        'app_url' => 'https://eventi.example.test',
        'mail_mailer' => 'log',
    ]);
    $this->post('/installazione/citta', [
        'name' => 'Padova',
        'province_code' => 'pd',
        'province_name' => 'Padova',
        'region' => 'Veneto',
        'timezone' => 'Europe/Rome',
        'center_lat' => '45.4064',
        'center_lng' => '11.8768',
        'radius_km' => '30',
    ]);

    /*
     * `from()` dichiara la pagina da cui arriva l'invio: è ciò che rende il
     * rimando verificabile, perché una validazione fallita riporta a dove si
     * era — nel browser lo dice la sessione, qui nessuno.
     */
    $this->from(InstallerStep::Admin->url())
        ->post('/installazione/amministratore', adminAnswers($overrides))
        ->assertRedirect(InstallerStep::Admin->url())
        ->assertSessionHasErrors($field);

    expect(User::query()->count())->toBe(0);
})->with([
    'senza nome' => [['name' => ''], 'name'],
    'senza email' => [['email' => ''], 'email'],
    'con un’email che non è un’email' => [['email' => 'chiocciola-mancante.test'], 'email'],
    'con una password di sette caratteri' => [['password' => 'sette12', 'password_confirmation' => 'sette12'], 'password'],
    'con la conferma diversa' => [['password_confirmation' => 'un-altra-password'], 'password'],
]);

it('non chiede al database se l’email è già presa, perché la tabella non esiste ancora', function (): void {
    /*
     * Una regola `unique:users` qui interrogherebbe una tabella che al momento
     * della compilazione non c'è: chi installa riceverebbe un errore di
     * connessione al posto di un messaggio di validazione, sul passo che
     * precede la creazione del database stesso. L'unicità resta garantita dal
     * vincolo della colonna, e l'operazione che crea l'utente riconosce
     * un'email già presente.
     */
    $rules = (new AdminStepRequest)->rules();

    $flattened = json_encode($rules);

    expect($flattened)->not->toContain('unique')
        ->and($flattened)->not->toContain('exists');
});

it('normalizza l’email prima di scriverla', function (): void {
    /*
     * `Admin@Example.TEST ` con la maiuscola e lo spazio finale è la stessa
     * casella di `admin@example.test`, ma non lo stesso valore: chi provasse a
     * entrare scrivendola come la scrive di solito non si riconoscerebbe.
     */
    installUpToContent(['email' => '  Admin@Example.TEST  ']);

    expect(User::query()->where('email', 'admin@example.test')->exists())->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('cifra la password e la rende buona per entrare', function (): void {
    installUpToContent();

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($admin->password)->not->toBe('password-lunga-1')
        ->and(Hash::check('password-lunga-1', $admin->password))->toBeTrue();
});

it('non lascia la password dell’amministratore nel .env', function (): void {
    /*
     * Il `.env` sopravvive all'installazione ed è leggibile da chiunque abbia
     * accesso al filesystem: la password del pannello non ha alcuna ragione di
     * finirci accanto a quella del database.
     */
    installUpToContent();

    expect($this->sandbox->envContents())->not->toContain('password-lunga-1');
});

it('rieseguire l’operazione non crea un secondo amministratore', function (): void {
    /*
     * D42 pretende che ogni operazione della checklist sia ripetibile senza
     * danno: un secondo clic, un ricaricamento, un ritentativo dopo un
     * timeout. Due amministratori con la stessa email non passerebbero
     * nemmeno il vincolo della colonna — l'installazione morirebbe lì.
     */
    installUpToContent();

    session(['installer.completed_tasks' => ['env', 'migrazioni', 'verifica-tabelle', 'dati-di-base']]);
    $this->post('/installazione/esecuzione');

    expect(User::query()->where('email', 'admin@example.test')->count())->toBe(1);
});

it('dà il ruolo a un utente che esisteva già con quell’email, senza duplicarlo', function (): void {
    /*
     * Succede quando la checklist si è fermata dopo la creazione dell'utente e
     * prima del marcatore: al ritentativo l'utente c'è già. Fallire lì
     * lascerebbe l'installazione bloccata a un passo dalla fine.
     */
    $existing = User::factory()->create(['email' => 'admin@example.test']);

    installUpToContent();

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect(User::query()->where('email', 'admin@example.test')->count())->toBe(1)
        ->and($admin->getKey())->toBe($existing->getKey())
        ->and($admin->hasRole('super_admin'))->toBeTrue();
});

it('segna l’email come già verificata, perché chi l’ha appena scritta è chi installa', function (): void {
    /*
     * Mandare un messaggio di verifica su un sistema dove la posta non è
     * ancora configurata sarebbe chiudere a chiave e lasciare la chiave dentro.
     */
    installUpToContent();

    expect(User::query()->where('email', 'admin@example.test')->firstOrFail()->email_verified_at)
        ->not->toBeNull();
});

it('crea un solo utente e nessun altro account', function (): void {
    /*
     * Il seed di produzione non porta utenti (D42, punto 5): se ne comparisse
     * uno, sarebbe un account con una password che nessuno ha scelto.
     */
    installUpToContent();

    expect(User::query()->count())->toBe(1);
});
