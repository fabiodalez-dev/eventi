<?php

declare(strict_types=1);

namespace App\Services\Installer;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Il marcatore di installazione avvenuta (D42, punto 2).
 *
 * Vive in `storage/app/private/install.lock` per tre ragioni verificate: è
 * fuori dalla document root, è già escluso da git, e il deploy lo salta
 * esplicitamente nel `rsync --delete` (`--exclude 'storage/app/private/*'` in
 * `ci.yml`, la stessa riga che protegge i backup). Un rilascio non lo cancella
 * né lo sovrascrive mai.
 *
 * Il file da solo però non decide niente: chi lo interroga (`InstallerGate`)
 * lo incrocia sempre con lo stato reale del database, perché un marcatore
 * presente su un database rotto è una bugia, e un marcatore assente su un
 * database vivo è un'installazione fatta prima che l'installer esistesse.
 */
class InstallLock
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Scrive il marcatore. Il contenuto è un JSON leggibile a occhio da chi
     * arriva in SSH: quando, con quale versione dell'applicazione, e a quale
     * migrazione era arrivato lo schema.
     */
    public function write(?string $schemaVersion = null): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Directory del marcatore non creabile: '.$directory);
        }

        $payload = json_encode([
            'installed_at' => Carbon::now()->toIso8601String(),
            'app_version' => config()->string('app.name'),
            'schema_version' => $schemaVersion,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false || @file_put_contents($this->path, $payload."\n") === false) {
            throw new RuntimeException('Marcatore non scrivibile: '.$this->path);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function path(): string
    {
        return $this->path;
    }
}
