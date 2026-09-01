<?php

declare(strict_types=1);

namespace App\Services\Installer;

use App\DTOs\Installer\Requirement;
use App\Enums\RequirementStatus;

/**
 * La tabella dei requisiti (D42, punto 4).
 *
 * La riga di confine fra un errore che blocca e un avviso che lascia
 * proseguire è una domanda sola: **il sito, dopo, risponde?** Se manca
 * `pdo_mysql` non risponde, e insistere sarebbe crudele; se manca `exif` le
 * foto ruotate restano storte e tutto il resto funziona, e bloccare
 * l'installazione per questo significherebbe non far installare nessuno.
 *
 * I permessi non si limitano a essere controllati: si prova a **ripararli**,
 * con `0775` sulle directory e `0664` sui file — mai `0777`, che apre la
 * scrittura a chiunque condivida il server. Se la riparazione non riesce, il
 * comando da dare a mano compare copiabile.
 */
class RequirementsChecker
{
    private const PHP_VERSION = '8.4.0';

    /** Le estensioni senza le quali il core non parte o il database non risponde. */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'mbstring',
        'openssl',
        'curl',
        'dom',
        'fileinfo',
        'intl',
        'bcmath',
        'json',
    ];

    public function __construct(
        private readonly string $basePath,
        private readonly EnvWriter $env,
    ) {}

    /**
     * @return array<int, Requirement>
     */
    public function all(): array
    {
        return [...$this->mandatory(), ...$this->advisory()];
    }

    /**
     * @param  array<int, Requirement>  $requirements
     */
    public function passes(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement->isBlocking()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Il motore immagini da scrivere in `.env`: Imagick se c'è, altrimenti GD.
     * Senza nessuno dei due non si arriva qui, perché è un bloccante.
     */
    public function imageDriver(): string
    {
        return extension_loaded('imagick') ? 'imagick' : 'gd';
    }

    /**
     * I requisiti senza i quali il sito, dopo, non risponde.
     *
     * @return array<int, Requirement>
     */
    public function mandatory(): array
    {
        $requirements = [];

        $requirements[] = new Requirement(
            'php',
            version_compare(PHP_VERSION, self::PHP_VERSION, '>=')
                ? RequirementStatus::Ok
                : RequirementStatus::Blocking,
            ['current' => PHP_VERSION, 'required' => self::PHP_VERSION],
        );

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $requirements[] = new Requirement(
                'extension',
                extension_loaded($extension) ? RequirementStatus::Ok : RequirementStatus::Blocking,
                ['extension' => $extension],
            );
        }

        /*
         * Senza alcun motore immagini l'intera pipeline media di §12.1 muore,
         * e le locandine sono il prodotto: è l'unico requisito «uno dei due»
         * dell'elenco.
         */
        $requirements[] = new Requirement(
            'image_engine',
            extension_loaded('imagick') || extension_loaded('gd')
                ? RequirementStatus::Ok
                : RequirementStatus::Blocking,
        );

        foreach (['storage', 'bootstrap/cache'] as $relative) {
            $path = $this->basePath.'/'.$relative;

            $requirements[] = new Requirement(
                'writable_directory',
                $this->ensureWritableDirectory($path) ? RequirementStatus::Ok : RequirementStatus::Blocking,
                ['path' => $relative],
                'chmod -R 0775 '.$relative,
            );
        }

        $requirements[] = new Requirement(
            'writable_env',
            $this->ensureWritableEnv() ? RequirementStatus::Ok : RequirementStatus::Blocking,
            ['path' => basename($this->env->path())],
            'chmod 0664 .env',
        );

        return $requirements;
    }

    /**
     * Avvisi: l'installazione procede, e ciascuno nomina la funzione che
     * perderà. Un avviso senza il nome di ciò che smette di funzionare è
     * rumore che si impara a ignorare.
     *
     * @return array<int, Requirement>
     */
    public function advisory(): array
    {
        $requirements = [];

        $requirements[] = new Requirement(
            'imagick',
            extension_loaded('imagick') ? RequirementStatus::Ok : RequirementStatus::Warning,
        );

        foreach (['exif', 'zip'] as $extension) {
            $requirements[] = new Requirement(
                'optional_'.$extension,
                extension_loaded($extension) ? RequirementStatus::Ok : RequirementStatus::Warning,
            );
        }

        $requirements[] = new Requirement(
            'symlink',
            $this->canSymlink() ? RequirementStatus::Ok : RequirementStatus::Warning,
            [],
            'php artisan storage:link',
        );

        $minimum = (int) config()->integer('media.max_upload_bytes');

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $current = (string) ini_get($directive);

            $requirements[] = new Requirement(
                'upload_size',
                $this->toBytes($current) >= $minimum ? RequirementStatus::Ok : RequirementStatus::Warning,
                [
                    'directive' => $directive,
                    'current' => $current === '' ? '?' : $current,
                    'required' => (string) (int) round($minimum / (1024 * 1024)).'M',
                ],
            );
        }

        return $requirements;
    }

    /**
     * Prova a rendere scrivibile una directory prima di dichiararla un
     * ostacolo. `0775` e non `0777`: il gruppo del server web basta, e una
     * directory aperta in scrittura a chiunque su una shared hosting è un
     * regalo al vicino di pianerottolo.
     */
    private function ensureWritableDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        if (is_writable($path)) {
            return true;
        }

        @chmod($path, 0775);

        return is_writable($path);
    }

    private function ensureWritableEnv(): bool
    {
        if ($this->env->isWritable()) {
            return true;
        }

        if ($this->env->exists()) {
            @chmod($this->env->path(), 0664);
        } else {
            @chmod(dirname($this->env->path()), 0775);
        }

        return $this->env->isWritable();
    }

    /**
     * `symlink()` esiste ed è utilizzabile? Su parecchie shared hosting è
     * elencata in `disable_functions`, e senza `public/storage` le immagini
     * non si servono affatto.
     */
    private function canSymlink(): bool
    {
        if (! function_exists('symlink')) {
            return false;
        }

        $disabled = array_map(
            static fn (string $function): string => trim($function),
            explode(',', (string) ini_get('disable_functions')),
        );

        return ! in_array('symlink', $disabled, true);
    }

    /** Traduce `12M`, `512K`, `1G` nel numero di byte che rappresentano. */
    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return 0;
        }

        /* `-1` significa «nessun limite»: è il valore più permissivo, non il più stretto. */
        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
