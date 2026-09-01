<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Enums\InstallerTask;
use App\Http\Middleware\InstallerSession;
use App\Models\City;
use App\Models\User;
use App\Services\Installer\InstallLock;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Support\InstallerSandbox;

/*
 * Il wizard, dall'inizio alla fine (D42, punto 3). Anti-salto, POST-redirect-GET
 * e checklist di esecuzione.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('riporta al primo passo incompleto chi salta avanti', function (): void {
    $this->get('/installazione/citta')->assertRedirect(InstallerStep::Requirements->url());
    $this->get('/installazione/esecuzione')->assertRedirect(InstallerStep::Requirements->url());

    $this->post('/installazione/requisiti');

    $this->get('/installazione/citta')->assertRedirect(InstallerStep::Database->url());
    $this->get('/installazione/database')->assertOk();
});

it('rifiuta anche un POST su un passo non ancora raggiungibile', function (): void {
    $this->post('/installazione/citta', ['name' => 'Padova'])
        ->assertRedirect(InstallerStep::Requirements->url());

    expect(City::query()->count())->toBe(0);
});

it('protegge ogni modulo con un token CSRF', function (): void {
    $this->post('/installazione/requisiti');

    $this->get('/installazione/database')->assertSee('name="_token"', false);
});

it('scrive la sessione su file durante l’installazione, non sul database', function (): void {
    /*
     * `SESSION_DRIVER=database` è il default del progetto, e il database che
     * dovrebbe ospitare la sessione è proprio quello che il wizard sta per
     * configurare.
     */
    config(['session.driver' => 'database']);

    $this->get('/installazione/requisiti')->assertOk();

    expect(config('session.driver'))->toBe('file');
});

it('usa un cookie di sessione che non dipende dal nome che si sta scegliendo', function (): void {
    /*
     * `config/session.php` ricava il nome del cookie da `APP_NAME`, e il
     * wizard scrive `APP_NAME` nel `.env` a metà installazione: senza un nome
     * fissato, dalla richiesta dopo il browser manderebbe un cookie che non
     * esiste più, la sessione ripartirebbe vuota e la prima operazione della
     * checklist risponderebbe 419 con tutte le risposte perdute. È successo
     * davvero, sul banco di prova, prima che questa riga esistesse.
     */
    $this->get('/installazione/requisiti')->assertOk();

    expect(config('session.cookie'))->toBe(InstallerSession::COOKIE);
});

it('ogni POST risponde con un rimando, mai con una pagina', function (): void {
    $this->post('/installazione/requisiti')
        ->assertStatus(302)
        ->assertRedirect(InstallerStep::Database->url());
});

it('rifiuta credenziali sbagliate con un messaggio diverso da quello del database mancante', function (): void {
    $credentials = testDatabaseCredentials();
    $credentials['db_username'] = 'utente-che-non-esiste';
    $credentials['db_password'] = 'sbagliata';

    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', $credentials)
        ->assertRedirect(InstallerStep::Database->url())
        ->assertSessionHas('installer_error', __('installer.database.errors.credentials'));
});

it('distingue un server che non risponde da credenziali sbagliate', function (): void {
    $credentials = testDatabaseCredentials();
    $credentials['db_port'] = '1';

    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', $credentials)
        ->assertSessionHas('installer_error', __('installer.database.errors.unreachable'));
});

it('crea il database quando non esiste, invece di mandare al pannello dell’hosting', function (): void {
    $name = 'eventi_probe_'.bin2hex(random_bytes(4));
    $credentials = testDatabaseCredentials();
    $credentials['db_database'] = $name;

    $this->post('/installazione/requisiti');

    try {
        $this->post('/installazione/database', $credentials)
            ->assertRedirect(InstallerStep::Application->url())
            ->assertSessionHas('installer_notice', __('installer.database.created', ['database' => $name]));
    } finally {
        DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
    }
});

it('non manda avanti chi non ha compilato il modulo del database', function (): void {
    $this->post('/installazione/requisiti');

    $this->post('/installazione/database', ['db_host' => '', 'db_port' => 'no'])
        ->assertSessionHasErrors(['db_host', 'db_port', 'db_database', 'db_username']);
});

it('limita i tentativi sulla prova di connessione', function (): void {
    $this->post('/installazione/requisiti');

    $credentials = testDatabaseCredentials();
    $credentials['db_port'] = '1';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post('/installazione/database', $credentials)->assertStatus(302);
    }

    $this->post('/installazione/database', $credentials)->assertStatus(429);
});

it('ricava lo slug della città dal nome quando lo si lascia vuoto', function (): void {
    completeThroughAdmin();

    $this->get('/installazione/esecuzione')->assertOk();

    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');

    expect(City::query()->where('slug', 'padova')->exists())->toBeTrue();
});

it('esegue la checklist un’operazione per volta e crea città e amministratore', function (): void {
    completeThroughAdmin();

    /* .env */
    $this->post('/installazione/esecuzione')->assertRedirect(InstallerStep::Run->url());
    expect($this->sandbox->envValue('APP_NAME'))->toBe('Prova inCittà')
        ->and($this->sandbox->envValue('APP_ENV'))->toBe('production')
        ->and($this->sandbox->envValue('APP_DEBUG'))->toBe('false')
        ->and($this->sandbox->envValue('CITY_DEFAULT_SLUG'))->toBe('padova')
        ->and($this->sandbox->envValue('OPS_ALERT_EMAIL'))->toBe('admin@example.test')
        ->and($this->sandbox->envValue('OPS_HEALTH_TOKEN'))->toStartWith('base64:');

    /* migrazioni, verifica delle tabelle, dati di base, città e amministratore */
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');

    /* Dalla fonte e non da un numero: vedi ProductionSeederTest. */
    expect(Permission::query()->count())->toBe(count(App\Enums\Permission::cases()));

    $city = City::query()->firstOrFail();
    expect($city->name)->toBe('Padova')
        ->and($city->slug)->toBe('padova')
        ->and($city->is_active)->toBeTrue()
        ->and($city->timezone)->toBe('Europe/Rome');

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
    expect($admin->hasRole('super_admin'))->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->isEditorialStaff())->toBeTrue();
});

it('chiude l’installazione scrivendo il marcatore e mostrando cron e integrazioni spente', function (): void {
    completeThroughAdmin();

    /*
     * L'ultima operazione della checklist (`config:cache`) non si esegue qui:
     * compilerebbe la configurazione **della suite** — ambiente `testing`,
     * database dei test — dentro `bootstrap/cache/config.php`, cioè nella
     * macchina che sta eseguendo i test. È verificata a mano sul banco di
     * prova descritto in D42. Qui la si dichiara già fatta.
     */
    session(['installer.completed_tasks' => [InstallerTask::Cache->value]]);

    /* Da qui in poi il database è davvero installato: l'ispettore finto ha finito il suo lavoro. */
    InstallerSandbox::useRealDatabase($this->app);

    /* Cinque operazioni, poi la sesta che chiude: la settima è già dichiarata fatta. */
    for ($task = 0; $task < 5; $task++) {
        $this->post('/installazione/esecuzione')->assertRedirect(InstallerStep::Run->url());
    }

    $this->post('/installazione/esecuzione')->assertRedirect(InstallerStep::Done->url());

    expect(app(InstallLock::class)->exists())->toBeTrue();

    $this->get('/installazione/fine')
        ->assertOk()
        ->assertSee('Prova inCittà')
        ->assertSee('artisan schedule:run', false)
        ->assertSee('artisan queue:work', false)
        ->assertSee(__('installer.done.integrations.items.turnstile'));
});

it('dimentica le password raccolte appena l’installazione è conclusa', function (): void {
    completeThroughAdmin();

    session(['installer.completed_tasks' => [InstallerTask::Cache->value]]);

    for ($task = 0; $task < 6; $task++) {
        $this->post('/installazione/esecuzione');
    }

    expect(session('installer.data'))->toBeNull();
});

it('chiude il .env a 600 a fine installazione', function (): void {
    completeThroughAdmin();

    session(['installer.completed_tasks' => [InstallerTask::Cache->value]]);

    for ($task = 0; $task < 6; $task++) {
        $this->post('/installazione/esecuzione');
    }

    expect(substr(sprintf('%o', fileperms($this->sandbox->envPath)), -3))->toBe('600');
});
