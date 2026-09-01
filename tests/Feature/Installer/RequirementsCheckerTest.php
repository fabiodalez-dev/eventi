<?php

declare(strict_types=1);

use App\DTOs\Installer\Requirement;
use App\Enums\RequirementStatus;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\RequirementsChecker;
use Tests\Support\InstallerSandbox;

/*
 * La tabella dei requisiti (D42, punto 4). La riga di confine fra bloccante e
 * avviso è una domanda sola: il sito, dopo, risponde?
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

/** @param array<int, Requirement> $requirements */
function keysOf(array $requirements): array
{
    return array_values(array_unique(array_map(fn (Requirement $requirement): string => $requirement->key, $requirements)));
}

it('considera indispensabili solo PHP, le estensioni del core, un motore immagini e i permessi', function (): void {
    expect(keysOf(app(RequirementsChecker::class)->mandatory()))
        ->toBe(['php', 'extension', 'image_engine', 'writable_directory', 'writable_env']);
});

it('non blocca mai su un avviso', function (): void {
    /*
     * Un installer che blocca su un avviso non fa installare nessuno. Nessuna
     * voce di questo elenco può diventare bloccante, qualunque sia lo stato
     * della macchina.
     */
    foreach (app(RequirementsChecker::class)->advisory() as $requirement) {
        expect($requirement->status)->not->toBe(RequirementStatus::Blocking);
    }
});

it('nomina imagick, exif, zip, i collegamenti simbolici e il limite di caricamento', function (): void {
    expect(keysOf(app(RequirementsChecker::class)->advisory()))
        ->toBe(['imagick', 'optional_exif', 'optional_zip', 'symlink', 'upload_size']);
});

it('su questa macchina i requisiti indispensabili sono soddisfatti', function (): void {
    $checker = app(RequirementsChecker::class);

    expect($checker->passes($checker->all()))->toBeTrue();
});

it('dichiara bloccante un .env che non si può scrivere e mostra il comando per rimediare', function (): void {
    /*
     * L'installer esiste per scrivere quel file: senza poterlo scrivere non
     * c'è niente che possa fare, e il messaggio deve dire quale comando dare
     * invece di limitarsi a fallire.
     */
    $checker = new RequirementsChecker(
        base_path(),
        new EnvWriter($this->sandbox->directory.'/cartella-inesistente/.env', $this->sandbox->templatePath),
    );

    $env = array_values(array_filter(
        $checker->mandatory(),
        fn (Requirement $requirement): bool => $requirement->key === 'writable_env',
    ))[0];

    expect($env->status)->toBe(RequirementStatus::Blocking)
        ->and($env->hint())->not->toBeEmpty()
        ->and($env->command)->toBe('chmod 0664 .env')
        ->and($checker->passes($checker->all()))->toBeFalse();
});

it('ripara i permessi di una cartella con 0775, mai con 0777', function (): void {
    $storage = $this->sandbox->directory.'/storage';
    mkdir($storage, 0500);

    $checker = new RequirementsChecker($this->sandbox->directory, app(EnvWriter::class));

    $repaired = array_values(array_filter(
        $checker->mandatory(),
        fn (Requirement $requirement): bool => $requirement->key === 'writable_directory',
    ));

    /* Due voci: `storage` e `bootstrap/cache`. */
    expect($repaired)->toHaveCount(2)
        ->and($repaired[0]->status)->toBe(RequirementStatus::Ok)
        ->and(substr(sprintf('%o', fileperms($storage)), -3))->toBe('775')
        ->and(is_writable($storage))->toBeTrue();

    rmdir($storage);
    rmdir($this->sandbox->directory.'/bootstrap/cache');
    rmdir($this->sandbox->directory.'/bootstrap');
});

it('sceglie il motore immagini guardando le estensioni, senza chiederlo', function (): void {
    expect(app(RequirementsChecker::class)->imageDriver())
        ->toBe(extension_loaded('imagick') ? 'imagick' : 'gd');
});
