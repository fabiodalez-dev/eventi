<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Services\Installer\InstallLock;
use Tests\Support\InstallerSandbox;

/*
 * Il cancello dell'installer (D42, punto 2). Il marcatore da solo non decide
 * niente: conta sempre incrociato con lo stato reale del database.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('apre il wizard quando non c’è marcatore e il database è vuoto', function (): void {
    InstallerSandbox::pretendEmptyDatabase($this->app);

    $this->get('/installazione')->assertRedirect(InstallerStep::Requirements->url());
});

it('risponde 404 e si auto-marca su un’installazione preesistente', function (): void {
    /*
     * È il caso di eventi.fabiodalez.it: in produzione da prima che
     * l'installer esistesse. Il primo rilascio che lo porta non deve mostrare
     * un wizard di installazione a un sistema installato.
     */
    expect(app(InstallLock::class)->exists())->toBeFalse();

    $this->get('/installazione/requisiti')->assertNotFound();

    expect(app(InstallLock::class)->exists())->toBeTrue();

    $marker = app(InstallLock::class)->read();

    expect($marker)->toHaveKeys(['installed_at', 'schema_version'])
        ->and($marker['schema_version'])->toBeString();
});

it('risponde 404 quando il marcatore c’è e il database sta bene', function (): void {
    app(InstallLock::class)->write('prova');

    $this->get('/installazione/requisiti')->assertNotFound();
    $this->get('/installazione/database')->assertNotFound();
});

it('con il marcatore presente e il database irraggiungibile mostra una diagnosi, non «già installato»', function (): void {
    app(InstallLock::class)->write('prova');
    InstallerSandbox::pretendUnreachableDatabase($this->app);

    $this->get('/installazione')
        ->assertStatus(503)
        ->assertSee(__('installer.diagnosis.title'))
        ->assertSee(__('installer.diagnosis.problems.connection'))
        ->assertSee('php artisan migrate --force');
});

it('con il marcatore presente e lo schema mancante spiega che il database è vuoto', function (): void {
    app(InstallLock::class)->write('prova');
    InstallerSandbox::pretendEmptyDatabase($this->app);

    $this->get('/installazione')
        ->assertStatus(503)
        ->assertSee(__('installer.diagnosis.problems.schema'));
});

it('non porta in pagina il messaggio di PDO né il nome del database', function (): void {
    app(InstallLock::class)->write('prova');
    InstallerSandbox::pretendUnreachableDatabase($this->app);

    $content = (string) $this->get('/installazione')->getContent();

    expect($content)
        ->not->toContain('SQLSTATE')
        ->not->toContain('PDOException')
        ->not->toContain(config()->string('database.connections.mariadb.database'));
});
