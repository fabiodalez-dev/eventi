<?php

declare(strict_types=1);

use App\Services\Installer\EnvWriter;
use Tests\Support\InstallerSandbox;

/*
 * La scrittura del `.env` (D42, punto 3). È il pezzo che, sbagliato, produce
 * guasti che arrivano giorni dopo e non assomigliano alla causa: una password
 * con un cancelletto scritta nuda tronca il valore, e il sintomo è «il sito
 * non si connette più al database» settimane più tardi.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('riscrive una password con cancelletto, spazio, apici, dollaro e backslash e la rilegge identica', function (): void {
    $password = 'p a#ss\'w"o$rd\\x`!$1';

    app(EnvWriter::class)->write([
        'DB_PASSWORD' => $password,
        'DB_DATABASE' => 'eventi',
    ]);

    expect($this->sandbox->envValue('DB_PASSWORD'))->toBe($password)
        ->and($this->sandbox->envValue('DB_DATABASE'))->toBe('eventi');
});

it('non lascia che un valore contenente $1 venga letto come riferimento a un gruppo di cattura', function (): void {
    /*
     * È la ragione per cui la sostituzione usa `preg_replace_callback` e non
     * `preg_replace`: nel testo di rimpiazzo del secondo, `$1` è il primo
     * gruppo catturato e sparirebbe dal file.
     */
    app(EnvWriter::class)->write(['DB_PASSWORD' => '$1$2\\0']);

    expect($this->sandbox->envValue('DB_PASSWORD'))->toBe('$1$2\\0');
});

it('lascia nudo un valore che non ha bisogno di virgolette', function (): void {
    app(EnvWriter::class)->write(['DB_HOST' => '127.0.0.1']);

    expect($this->sandbox->envContents())->toContain("\nDB_HOST=127.0.0.1\n");
});

it('scrive una stringa vuota come coppia di virgolette e non come riga monca', function (): void {
    app(EnvWriter::class)->write(['DB_PASSWORD' => '']);

    expect($this->sandbox->envContents())->toContain('DB_PASSWORD=""')
        ->and($this->sandbox->envValue('DB_PASSWORD'))->toBe('');
});

it('parte dal modello e conserva le variabili che nessuno ha toccato', function (): void {
    app(EnvWriter::class)->write(['APP_NAME' => 'Prova']);

    expect($this->sandbox->envValue('APP_NAME'))->toBe('Prova')
        ->and($this->sandbox->envValue('SCOUT_DRIVER'))->toBe('database')
        ->and($this->sandbox->envValue('TURNSTILE_SITE_KEY'))->toBe('');
});

it('aggiunge in coda una chiave che il modello non prevede', function (): void {
    app(EnvWriter::class)->write(['CHIAVE_NUOVA' => 'valore']);

    expect($this->sandbox->envValue('CHIAVE_NUOVA'))->toBe('valore');
});

it('chiude il file a 600, perché contiene la password del database', function (): void {
    app(EnvWriter::class)->write(['DB_PASSWORD' => 'segreta']);

    expect(substr(sprintf('%o', fileperms($this->sandbox->envPath)), -3))->toBe('600');
});

it('non lascia in giro il file temporaneo della scrittura atomica', function (): void {
    app(EnvWriter::class)->write(['DB_PASSWORD' => 'segreta']);

    expect(glob($this->sandbox->directory.'/.env-*'))->toBe([]);
});

it('aggiorna una sola chiave lasciando intatto tutto il resto', function (): void {
    $writer = app(EnvWriter::class);
    $writer->write(['APP_NAME' => 'Prima', 'DB_DATABASE' => 'uno']);
    $writer->update(['DB_DATABASE' => 'due']);

    expect($this->sandbox->envValue('APP_NAME'))->toBe('Prima')
        ->and($this->sandbox->envValue('DB_DATABASE'))->toBe('due');
});
