<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Models\City;
use App\Services\Installer\InstallLock;
use Tests\Support\InstallerSandbox;

/*
 * Cosa succede quando un'operazione non riesce (D42, punto 4).
 *
 * Nessun errore grezzo: ogni guasto porta con sé la soluzione e un modo per
 * riprovare, e il dettaglio tecnico resta nel log.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('si ferma se dopo le migrazioni mancano delle tabelle, dicendo quali e come riprovare', function (): void {
    completeThroughAdmin();

    /* .env e migrazioni */
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');

    InstallerSandbox::pretendMissingTables($this->app, ['cities', 'venues']);

    $this->post('/installazione/esecuzione')
        ->assertRedirect(InstallerStep::Run->url())
        ->assertSessionHas('installer_command', 'php artisan migrate --force');

    $page = $this->get('/installazione/esecuzione');

    $page->assertOk()
        ->assertSee('cities, venues')
        ->assertSee('php artisan migrate --force')
        ->assertSee(__('installer.actions.run'));

    /* Il guasto ferma la catena: niente città, niente amministratore, niente marcatore. */
    expect(City::query()->count())->toBe(0)
        ->and(app(InstallLock::class)->exists())->toBeFalse();
});

it('riprende dall’operazione fallita senza rifare quelle già riuscite', function (): void {
    completeThroughAdmin();

    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');

    InstallerSandbox::pretendMissingTables($this->app, ['cities']);
    $this->post('/installazione/esecuzione');

    /* Rimesso a posto il database, la stessa operazione riparte da dove si era fermata. */
    InstallerSandbox::pretendEmptyDatabase($this->app);

    $this->post('/installazione/esecuzione')->assertRedirect(InstallerStep::Run->url());
    $this->post('/installazione/esecuzione');
    $this->post('/installazione/esecuzione');

    expect(City::query()->where('slug', 'padova')->exists())->toBeTrue();
});
