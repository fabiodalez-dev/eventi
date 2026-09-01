<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallLock;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;

/**
 * Un banco di prova per l'installer che non tocca niente della macchina che
 * esegue i test.
 *
 * Serve perché l'installer, per mestiere, scrive il `.env` e il marcatore di
 * installazione: una suite che lo esercitasse davvero riscriverebbe la
 * configurazione dello sviluppatore e dichiarerebbe installato il progetto in
 * cui sta girando. Qui i tre percorsi — `.env`, modello, marcatore — puntano a
 * una directory temporanea, e le sessioni pure.
 */
final class InstallerSandbox
{
    private function __construct(
        public readonly string $directory,
        public readonly string $envPath,
        public readonly string $templatePath,
        public readonly string $lockPath,
    ) {}

    public static function make(Application $app): self
    {
        $directory = sys_get_temp_dir().'/installer-'.bin2hex(random_bytes(6));
        mkdir($directory.'/sessions', 0775, true);

        $sandbox = new self(
            $directory,
            $directory.'/.env',
            $directory.'/.env.example',
            $directory.'/install.lock',
        );

        copy(base_path('.env.example'), $sandbox->templatePath);

        $app->instance(EnvWriter::class, new EnvWriter($sandbox->envPath, $sandbox->templatePath));
        $app->instance(InstallLock::class, new InstallLock($sandbox->lockPath));

        config(['session.files' => $directory.'/sessions']);

        return $sandbox;
    }

    /**
     * Fa credere all'installer che il database sia vuoto.
     *
     * Il database dei test è per forza già migrato — `RefreshDatabase` lo
     * ricostruisce a ogni caso di prova — e senza questo il cancello
     * dell'installer scambierebbe la suite per un'installazione preesistente,
     * si auto-marcerebbe e risponderebbe 404 a ogni schermata del wizard.
     */
    public static function pretendEmptyDatabase(Application $app): void
    {
        $app->instance(DatabaseInspector::class, new class(database_path('migrations')) extends DatabaseInspector
        {
            public function hasSchema(): bool
            {
                return false;
            }
        });
    }

    /**
     * Rimette in servizio l'ispettore vero.
     *
     * Serve nei casi che arrivano in fondo all'installazione: da lì in poi il
     * database dei test *è* uno schema completo, ed è quello che il cancello
     * deve vedere per lasciar leggere la schermata finale.
     */
    public static function useRealDatabase(Application $app): void
    {
        $app->instance(DatabaseInspector::class, new DatabaseInspector(database_path('migrations')));
    }

    /**
     * Un database in cui, dopo le migrazioni, mancano delle tabelle: è il caso
     * che la verifica esiste per intercettare.
     *
     * @param  array<int, string>  $tables
     */
    public static function pretendMissingTables(Application $app, array $tables): void
    {
        $app->instance(DatabaseInspector::class, new class(database_path('migrations'), $tables) extends DatabaseInspector
        {
            /**
             * @param  array<int, string>  $missing
             */
            public function __construct(string $migrationsPath, private readonly array $missing)
            {
                parent::__construct($migrationsPath);
            }

            public function hasSchema(): bool
            {
                return false;
            }

            public function missingTables(): array
            {
                return $this->missing;
            }
        });
    }

    /** Un database irraggiungibile: è il caso della diagnosi. */
    public static function pretendUnreachableDatabase(Application $app): void
    {
        $app->instance(DatabaseInspector::class, new class(database_path('migrations')) extends DatabaseInspector
        {
            public function canConnect(): bool
            {
                return false;
            }
        });
    }

    public function envContents(): string
    {
        return is_file($this->envPath) ? (string) file_get_contents($this->envPath) : '';
    }

    /**
     * Il valore di una variabile così come il `.env` scritto viene riletto da
     * chi lo legge davvero: il parser di Dotenv, non un'espressione regolare
     * scritta per l'occasione, che potrebbe sbagliare esattamente dove sbaglia
     * il codice che sta verificando.
     */
    public function envValue(string $key): ?string
    {
        $parsed = Dotenv::parse($this->envContents());

        return $parsed[$key] ?? null;
    }

    public function cleanup(): void
    {
        foreach (glob($this->directory.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach (glob($this->directory.'/sessions/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory.'/sessions');
        @rmdir($this->directory);
    }
}
