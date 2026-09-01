<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Services\Installer\InstallLock;
use Illuminate\Support\Facades\DB;
use Tests\Support\InstallerSandbox;

/*
 * Il marcatore di installazione, nelle due direzioni (D42, punto 2).
 *
 * Con il marcatore l'installer non riparte; senza, riparte. Sembra ovvio, ma è
 * la sola cosa che separa un endpoint di configurazione pubblico e non
 * autenticato da un sito vivo — e il 404 deve valere per **ogni** rotta del
 * gruppo, non per quelle che qualcuno si è ricordato di controllare.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('tiene il marcatore fuori dalla cartella pubblica', function (): void {
    /*
     * Dentro `public/` sarebbe scaricabile da chiunque, e il suo contenuto dice
     * quando il sito è stato installato e a quale migrazione è arrivato lo
     * schema. Il binding vero si ottiene togliendo di mezzo quello del banco di
     * prova, che punta a una directory temporanea.
     */
    $this->app->forgetInstance(InstallLock::class);

    $path = app(InstallLock::class)->path();

    expect($path)->toBe(storage_path('app/private/install.lock'))
        ->and($path)->not->toStartWith(public_path());
});

it('risponde 404 con il marcatore presente su', function (string $url): void {
    app(InstallLock::class)->write('prova');

    $this->get($url)->assertNotFound();
})->with([
    '/installazione',
    '/installazione/requisiti',
    '/installazione/applicazione',
    '/installazione/citta',
    '/installazione/amministratore',
    '/installazione/esecuzione',
    '/installazione/fine',
]);

it('chiude anche i POST, non solo le pagine da leggere', function (string $url): void {
    /*
     * Le pagine chiuse e i moduli aperti sarebbero il peggiore dei due mondi:
     * chi passa non vedrebbe niente, e chi conosce gli indirizzi potrebbe
     * comunque riscrivere il `.env` di un sito in esercizio.
     */
    app(InstallLock::class)->write('prova');

    $this->post($url, [])->assertNotFound();
})->with([
    '/installazione/requisiti',
    '/installazione/database',
    '/installazione/esecuzione',
]);

it('riapre il wizard quando il marcatore viene tolto', function (): void {
    /*
     * È il gesto documentato nel RUNBOOK per reinstallare davvero: si cancella
     * il file via SSH. Senza database installato, la procedura riparte dal
     * primo passo.
     */
    app(InstallLock::class)->write('prova');
    $this->get('/installazione')->assertNotFound();

    unlink($this->sandbox->lockPath);
    InstallerSandbox::pretendEmptyDatabase($this->app);

    $this->get('/installazione')->assertRedirect(InstallerStep::Requirements->url());
});

it('annota nel marcatore la migrazione più recente davvero applicata', function (): void {
    /*
     * `schema_version` è ciò che, in SSH, dice se il database è indietro
     * rispetto ai file. Un valore inventato o fisso renderebbe il file inutile
     * proprio nel momento in cui lo si va a leggere.
     */
    $this->get('/installazione/requisiti')->assertNotFound();

    $marker = app(InstallLock::class)->read();

    expect($marker['schema_version'])
        ->toBe(DB::table('migrations')->orderByDesc('id')->value('migration'));
});

it('scrive nel marcatore una data leggibile e il nome dell’applicazione', function (): void {
    app(InstallLock::class)->write('2026_09_01_000000_prova');

    $marker = app(InstallLock::class)->read();

    expect($marker['app_version'])->toBe(config()->string('app.name'))
        ->and($marker['schema_version'])->toBe('2026_09_01_000000_prova')
        ->and(strtotime((string) $marker['installed_at']))->not->toBeFalse();
});

it('tiene chiuso l’installer anche se il marcatore è illeggibile', function (): void {
    /*
     * Un file troncato da un disco pieno resta un marcatore: la domanda che
     * conta è «esiste», non «si riesce a leggerlo». Il contrario riaprirebbe il
     * wizard su un sito installato proprio nel giorno storto.
     */
    file_put_contents($this->sandbox->lockPath, '{ questo non è JSON');

    $lock = app(InstallLock::class);

    expect($lock->exists())->toBeTrue()
        ->and($lock->read())->toBeNull();

    $this->get('/installazione')->assertNotFound();
});

it('crea la cartella del marcatore se non c’è ancora', function (): void {
    /*
     * `storage/app/private/` esiste in questo repository, ma su un rilascio
     * fatto con `rsync` di una copia senza contenuti di `storage/` può non
     * esserci: se la scrittura fallisse lì, l'installazione si chiuderebbe
     * senza marcatore e il wizard resterebbe aperto al mondo.
     */
    $nested = $this->sandbox->directory.'/mancante/anche-questa/install.lock';

    (new InstallLock($nested))->write('prova');

    expect(is_file($nested))->toBeTrue();

    unlink($nested);
    rmdir(dirname($nested));
    rmdir(dirname($nested, 2));
});
