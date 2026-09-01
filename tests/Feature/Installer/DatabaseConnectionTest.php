<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Services\Installer\DatabaseInspector;
use Illuminate\Support\Facades\Log;
use Tests\Support\InstallerSandbox;

/*
 * I guasti della connessione al database, uno per uno (D42, punto 3, passo 2).
 *
 * Quattro cose vanno storte in modo diverso — nessuno risponde, risponde e ti
 * manda via, il database non c'è, il database c'è ma non è tuo — e hanno
 * quattro rimedi diversi: controllare host e porta, ricopiare l'utente dal
 * pannello, crearlo, associarlo. Un installer che le riassume in «impossibile
 * connettersi» costringe chi installa a indovinare quale delle quattro sia, e
 * di solito indovina l'ultima.
 *
 * I due casi centrali non si possono simulare con l'utente `root` dei test:
 * servono utenti veri con privilegi limitati, creati qui e cancellati subito
 * dopo. È l'unico modo di far dire al server esattamente ciò che direbbe sulla
 * shared hosting di chi installa.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

/**
 * Una connessione amministrativa **fuori** da Laravel.
 *
 * `CREATE USER` e `GRANT` sono comandi che il server esegue con una commit
 * implicita: dati sulla connessione dei test, chiuderebbero la transazione che
 * `RefreshDatabase` tiene aperta e i dati del caso di prova resterebbero nel
 * database. Su una sessione a parte non toccano niente.
 */
function privilegedConnection(): PDO
{
    return new PDO(
        'mysql:host='.config()->string('database.connections.mariadb.host')
            .';port='.(string) config('database.connections.mariadb.port'),
        config()->string('database.connections.mariadb.username'),
        (string) config('database.connections.mariadb.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * Crea un utente con i soli privilegi indicati e lo cancella alla fine.
 *
 * L'utente è dichiarato sia su `localhost` sia su `%`: da 127.0.0.1 MariaDB
 * risolve il nome dell'host, e su una macchina con l'utente anonimo
 * `''@'localhost'` la voce con il carattere jolly non verrebbe mai scelta.
 *
 * @param  callable(string, string): void  $body
 */
function withLimitedUser(string $grant, callable $body): void
{
    $user = 'eventi_probe_'.bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(8));
    $server = privilegedConnection();

    foreach (['localhost', '%'] as $host) {
        $server->exec("CREATE USER '".$user."'@'".$host."' IDENTIFIED BY '".$password."'");
        $server->exec('GRANT '.$grant." TO '".$user."'@'".$host."'");
    }

    $server->exec('FLUSH PRIVILEGES');

    try {
        $body($user, $password);
    } finally {
        foreach (['localhost', '%'] as $host) {
            $server->exec("DROP USER IF EXISTS '".$user."'@'".$host."'");
        }
    }
}

function probeInspector(): DatabaseInspector
{
    return new DatabaseInspector(database_path('migrations'));
}

it('dice «il database non esiste e non posso crearlo» quando le credenziali sono buone ma il permesso manca', function (): void {
    /*
     * L'utente vede che il database non c'è (errore 1049) ma non può crearlo:
     * è il caso della shared hosting dove i database si aprono solo dal
     * pannello. Prima di arrivare qui l'installer ha *provato* a crearlo, che è
     * ciò che rende accettabile mandare al pannello quando fallisce.
     */
    withLimitedUser('SELECT ON *.*', function (string $user, string $password): void {
        $probe = probeInspector()->probe(
            config()->string('database.connections.mariadb.host'),
            (int) config('database.connections.mariadb.port'),
            'eventi_probe_assente_'.bin2hex(random_bytes(3)),
            $user,
            $password,
        );

        expect($probe->ok)->toBeFalse()
            ->and($probe->message())->toBe(__('installer.database.errors.database_missing'));
    });
});

it('dice «quel database non è tuo» quando esiste ma l’utente non vi è associato', function (): void {
    /*
     * Il guasto più frequente su cPanel e Plesk: il database e l'utente sono
     * stati creati, ma nessuno ha premuto «aggiungi utente al database». Le
     * credenziali sono giuste, il database esiste, e non è la stessa cosa di
     * un database mancante — il rimedio sta in un'altra schermata del pannello.
     */
    $database = 'eventi_probe_negato_'.bin2hex(random_bytes(3));
    $server = privilegedConnection();
    $server->exec('CREATE DATABASE `'.$database.'`');

    try {
        withLimitedUser('SELECT ON `'.config()->string('database.connections.mariadb.database').'`.*',
            function (string $user, string $password) use ($database): void {
                $probe = probeInspector()->probe(
                    config()->string('database.connections.mariadb.host'),
                    (int) config('database.connections.mariadb.port'),
                    $database,
                    $user,
                    $password,
                );

                expect($probe->ok)->toBeFalse()
                    ->and($probe->message())->toBe(__('installer.database.errors.database_refused'));
            });
    } finally {
        $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    }
});

it('tratta un host che non si risolve come una porta chiusa, perché il rimedio è lo stesso', function (): void {
    /*
     * Un nome che il DNS non conosce e una porta che non risponde sono due
     * guasti diversi per il sistema operativo e lo stesso guasto per chi
     * installa: ha sbagliato a copiare l'indirizzo del server. Distinguerli in
     * pagina significherebbe raccontare a un estraneo quali nomi risolvono.
     */
    $probe = probeInspector()->probe(
        'host-che-non-esiste-'.bin2hex(random_bytes(4)).'.invalid',
        3306,
        'eventi',
        'utente',
        'password',
    );

    expect($probe->ok)->toBeFalse()
        ->and($probe->message())->toBe(__('installer.database.errors.unreachable'));
});

it('ha un messaggio diverso per ciascuno dei cinque guasti', function (): void {
    /*
     * Se due chiavi puntassero allo stesso testo, la distinzione fatta dal
     * codice non arriverebbe a chi legge — e tutto il doppio tentativo di
     * connessione sarebbe lavoro sprecato.
     */
    $messages = array_map(
        static fn (string $key): string => __('installer.database.errors.'.$key),
        ['unreachable', 'credentials', 'database_missing', 'database_refused', 'database_name_invalid'],
    );

    expect(array_unique($messages))->toHaveCount(5)
        ->and(array_filter($messages, static fn (string $message): bool => $message === ''))->toBe([]);
});

it('non porta in pagina il dettaglio tecnico quando', function (array $overrides): void {
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', [...testDatabaseCredentials(), ...$overrides]);

    $content = (string) $this->get('/installazione/database')->getContent();

    expect($content)
        ->not->toContain('SQLSTATE')
        ->not->toContain('PDOException')
        ->not->toContain('Access denied')
        ->not->toContain('Connection refused');
})->with([
    'la porta è chiusa' => [['db_port' => '1']],
    'utente e password sono sbagliati' => [['db_username' => 'utente-inesistente', 'db_password' => 'no']],
    'il nome dell’host non si risolve' => [['db_host' => 'host-che-non-esiste.invalid']],
]);

it('non rimanda mai in pagina la password del database digitata', function (): void {
    /*
     * Il modulo si ripropone compilato dopo un errore — è l'unica cosa civile
     * da fare — ma la password no: resterebbe nel sorgente della pagina, cioè
     * nella cache del browser e in qualunque proxy in mezzo.
     */
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', [
        ...testDatabaseCredentials(),
        'db_port' => '1',
        'db_password' => 'password-segretissima-1234',
    ]);

    $this->get('/installazione/database')
        ->assertOk()
        ->assertDontSee('password-segretissima-1234');
});

it('ripropone host, porta, nome e utente dopo un errore', function (): void {
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', [
        ...testDatabaseCredentials(),
        'db_host' => 'db.example.test',
        'db_port' => '3399',
        'db_database' => 'eventi_prod',
        'db_username' => 'utente_con_prefisso',
    ]);

    $this->get('/installazione/database')
        ->assertSee('value="db.example.test"', false)
        ->assertSee('value="3399"', false)
        ->assertSee('value="eventi_prod"', false)
        ->assertSee('value="utente_con_prefisso"', false);
});

it('scrive nel log host, porta e codice di errore, che in pagina non compaiono', function (): void {
    Log::spy();

    probeInspector()->probe('127.0.0.1', 1, 'eventi_prova', 'utente', 'password');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['host'] === '127.0.0.1'
            && $context['port'] === 1
            && array_key_exists('codice', $context))
        ->once();
});

it('il limite di tentativi ferma le prove, non la lettura del modulo', function (): void {
    /*
     * Il limite protegge da chi usa l'installer come scanner di porte altrui.
     * Se chiudesse anche la pagina, chi ha sbagliato a copiare le credenziali
     * dieci volte resterebbe fuori dalla propria installazione per un minuto,
     * senza nemmeno poter rileggere ciò che aveva scritto.
     */
    $this->post('/installazione/requisiti');

    $credentials = [...testDatabaseCredentials(), 'db_port' => '1'];

    for ($attempt = 0; $attempt < 11; $attempt++) {
        $this->post('/installazione/database', $credentials);
    }

    $this->post('/installazione/database', $credentials)->assertStatus(429);
    $this->get('/installazione/database')->assertOk();
});

it('rifiuta prima di aprire qualsiasi connessione un nome di database', function (string $name): void {
    /*
     * `CREATE DATABASE` non accetta segnaposto: il nome finisce interpolato
     * nella riga SQL. La regola di validazione è ciò che tiene quella riga
     * innocua, e deve rispondere prima che qualunque cosa raggiunga il server.
     */
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', [...testDatabaseCredentials(), 'db_database' => $name])
        ->assertSessionHasErrors('db_database');
})->with([
    'con un trattino' => 'nome-con-trattino',
    'con un punto e virgola' => 'eventi; DROP DATABASE mysql',
    'con un accento grave' => 'eventi`',
    'con uno spazio' => 'nome database',
    'vuoto' => '',
]);

it('accetta una password vuota, che su certi hosting è la sola che esiste', function (): void {
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', [...testDatabaseCredentials(), 'db_password' => ''])
        ->assertSessionHasNoErrors();
});

it('sa distinguere un database vuoto da uno installato', function (): void {
    /*
     * `hasSchema()` è ciò su cui si regge l'auto-marcatura: se rispondesse di
     * sì a un database che si connette ma è vuoto, il primo rilascio su un
     * server nuovo si dichiarerebbe installato e nessuno potrebbe più
     * installarlo dal web.
     */
    expect(app(DatabaseInspector::class)->hasSchema())->toBeFalse()
        ->and(probeInspector()->hasSchema())->toBeTrue();
});

it('riparte dal passo del database chi torna indietro dopo averlo superato', function (): void {
    /*
     * Un database dichiarato per sbaglio si corregge tornando sul modulo: le
     * risposte precedenti si rivedono, e la prova di connessione si rifà da
     * capo con quelle nuove.
     */
    $this->post('/installazione/requisiti');
    $this->post('/installazione/database', testDatabaseCredentials())
        ->assertRedirect(InstallerStep::Application->url());

    $this->get('/installazione/database')
        ->assertOk()
        ->assertSee('value="'.config()->string('database.connections.mariadb.database').'"', false);
});
