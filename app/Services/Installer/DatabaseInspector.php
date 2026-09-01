<?php

declare(strict_types=1);

namespace App\Services\Installer;

use App\DTOs\Installer\ConnectionProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use Throwable;

/**
 * Prova la connessione al database e verifica che lo schema ci sia davvero
 * (D42, punto 3).
 *
 * **Si usa PDO diretto, non `DB::connection()`.** Nel momento in cui questa
 * classe lavora il `.env` non è ancora stato scritto: la configurazione di
 * Laravel descrive un database diverso — quello del modello, o nessuno — e
 * `DB::connection()` proverebbe quello invece delle credenziali appena
 * digitate. La connessione va aperta con i parametri che si stanno provando,
 * non con quelli che il framework ha in memoria.
 *
 * **Ci si connette due volte, e non è uno spreco.** Prima *senza* nome del
 * database: se questa fallisce, il guasto è l'host, la porta o le credenziali.
 * Poi *con* il nome: se fallisce solo la seconda, il server risponde e le
 * credenziali sono buone — manca il database, che è un altro problema e ha
 * un'altra soluzione. Un solo tentativo confonderebbe i due casi in un unico
 * messaggio inutile.
 */
class DatabaseInspector
{
    /** Secondi oltre i quali un host che non risponde si dichiara irraggiungibile. */
    private const TIMEOUT = 5;

    public function __construct(private readonly string $migrationsPath) {}

    /**
     * Prova le credenziali e, se il database non esiste, tenta di crearlo con
     * le stesse credenziali prima di arrendersi: su molti hosting l'utente ha
     * il permesso, e chiedere di aprire il pannello quando non serve è un
     * ostacolo gratuito.
     */
    public function probe(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
    ): ConnectionProbe {
        try {
            $server = $this->connect($this->dsn($host, $port), $username, $password);
        } catch (PDOException $exception) {
            return ConnectionProbe::failure($this->classifyServerError($exception, $host, $port));
        }

        try {
            $this->connect($this->dsn($host, $port, $database), $username, $password);

            return ConnectionProbe::success();
        } catch (PDOException $exception) {
            if (! $this->isUnknownDatabase($exception)) {
                $this->log('connessione al database rifiutata', $exception, $host, $port, $database);

                return ConnectionProbe::failure('database_refused');
            }
        }

        return $this->createDatabase($server, $database, $host, $port);
    }

    /**
     * Lo schema c'è? Non basta che la connessione si apra: un database vuoto
     * si connette benissimo. Conta che la tabella `migrations` esista e sia
     * popolata, cioè che qualcuno abbia davvero installato.
     */
    public function hasSchema(): bool
    {
        try {
            return Schema::hasTable('migrations')
                && DB::table('migrations')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function canConnect(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Le tabelle che le migrazioni dichiarano di creare.
     *
     * L'elenco si **deriva** dai file di `database/migrations` leggendo i nomi
     * passati a `Schema::create()`: un elenco scritto a mano mentirebbe alla
     * prima migrazione nuova, e mentirebbe proprio nel controllo che dovrebbe
     * accorgersi di una migrazione non applicata.
     *
     * @return array<int, string>
     */
    public function expectedTables(): array
    {
        $tables = [];

        foreach (glob($this->migrationsPath.'/*.php') ?: [] as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            if (preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $contents, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $table) {
                $tables[$table] = true;
            }
        }

        $names = array_keys($tables);
        sort($names);

        return $names;
    }

    /**
     * Le tabelle attese che nel database non ci sono. Vuoto è la sola risposta
     * accettabile dopo `migrate`.
     *
     * @return array<int, string>
     */
    public function missingTables(): array
    {
        try {
            $existing = Schema::getTableListing(schema: null, schemaQualified: false);
        } catch (Throwable $exception) {
            $this->log('elenco delle tabelle non leggibile', $exception);

            return $this->expectedTables();
        }

        return array_values(array_diff($this->expectedTables(), $existing));
    }

    private function createDatabase(PDO $server, string $database, string $host, int $port): ConnectionProbe
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            return ConnectionProbe::failure('database_name_invalid');
        }

        try {
            $server->exec(
                'CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            return ConnectionProbe::success(databaseCreated: true);
        } catch (PDOException $exception) {
            $this->log('creazione del database non riuscita', $exception, $host, $port, $database);

            return ConnectionProbe::failure('database_missing');
        }
    }

    private function dsn(string $host, int $port, ?string $database = null): string
    {
        $dsn = 'mysql:host='.$host.';port='.$port;

        return $database === null ? $dsn : $dsn.';dbname='.$database;
    }

    private function connect(string $dsn, string $username, string $password): PDO
    {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::TIMEOUT,
        ]);
    }

    /**
     * Traduce l'errore del server in una delle due diagnosi che chi installa
     * può davvero agire: «non risponde nessuno» oppure «risponde e ti manda
     * via». Il resto del messaggio di PDO resta nel log.
     */
    private function classifyServerError(PDOException $exception, string $host, int $port): string
    {
        $this->log('connessione al server rifiutata', $exception, $host, $port);

        $code = (string) $exception->getCode();

        return in_array($code, ['1045', '1044', '28000'], true)
            ? 'credentials'
            : 'unreachable';
    }

    private function isUnknownDatabase(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '1049'
            || str_contains($exception->getMessage(), '1049');
    }

    /**
     * Il dettaglio tecnico va **solo** qui. L'endpoint dell'installer è
     * pubblico per costruzione: un errore PDO mostrato in pagina racconterebbe
     * a un estraneo quali host e quali porte rispondono.
     */
    private function log(
        string $what,
        Throwable $exception,
        ?string $host = null,
        ?int $port = null,
        ?string $database = null,
    ): void {
        Log::warning('Installer: '.$what, array_filter([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'codice' => $exception->getCode(),
            'errore' => $exception->getMessage(),
        ], static fn (mixed $value): bool => $value !== null));
    }
}
