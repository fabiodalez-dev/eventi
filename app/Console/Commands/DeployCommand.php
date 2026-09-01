<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Il rilascio, eseguito **dal server su se stesso**.
 *
 * **Perché al contrario.** Il verso naturale sarebbe che l'integrazione
 * continua spinga i file sul server via SSH: è ciò che il workflow faceva, e
 * non ha mai funzionato — la porta 22 di questo host non accetta connessioni
 * da fuori, e ogni tentativo moriva in `Connection timed out`. Le connessioni
 * in USCITA invece passano: il server raggiunge GitHub sia in HTTPS sia in
 * SSH. Quindi è il server a tirare, e l'unica cosa che arriva da fuori è un
 * segnale — una richiesta HTTPS su una rotta protetta da segreto.
 *
 * **Cosa fa, in ordine, e perché quest'ordine.** Prima porta il codice, poi le
 * dipendenze (il `composer.lock` appena arrivato può chiederne di nuove), poi
 * le migrazioni (il codice nuovo può pretendere colonne che non esistono
 * ancora), poi ruoli e permessi (un permesso nuovo non esiste finché non lo si
 * semina, e la sezione che protegge risponde 403 anche all'amministratore),
 * e per ultime le cache — che vanno rifatte quando tutto il resto è già a
 * posto, altrimenti congelano lo stato di mezzo.
 *
 * **Cosa non tocca**: `.env`, `vendor` prima di aggiornarlo, `storage`, i
 * media caricati. Sono di questa installazione, non del repository.
 */
class DeployCommand extends Command
{
    protected $signature = 'deploy:pull
        {--branch=main : Il ramo da rilasciare}
        {--skip-composer : Salta le dipendenze PHP, se si sa che il lock non è cambiato}
        {--skip-assets : Salta la compilazione di CSS e JavaScript}';

    protected $description = 'Porta il server all\'ultimo commit del ramo e riallinea database, permessi e cache.';

    /**
     * Il PHP con cui gira l'applicazione, che sulla shared hosting **non è**
     * quello della shell: `composer` invocato senza dirglielo usa il PHP
     * predefinito (8.3) e rifiuta un lock che pretende 8.4.
     */
    private function php(): string
    {
        $candidati = [
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            PHP_BINARY,
        ];

        foreach ($candidati as $php) {
            if (is_executable($php)) {
                return $php;
            }
        }

        return 'php';
    }

    /**
     * Se `package-lock.json` sia cambiato dall'ultima installazione.
     *
     * Si confronta l'impronta del lock con quella salvata a fine rilascio.
     * Senza `node_modules` la risposta è comunque sì — non c'è niente da
     * riusare.
     */
    private function lockNodeCambiato(): bool
    {
        if (! is_dir(base_path('node_modules'))) {
            return true;
        }

        $lock = base_path('package-lock.json');

        if (! is_file($lock)) {
            return false;
        }

        $impronta = base_path('node_modules/.eventi-lock-hash');

        return ! is_file($impronta) || trim((string) file_get_contents($impronta)) !== md5_file($lock);
    }

    public function handle(): int
    {
        $branch = (string) $this->option('branch');

        /* Solo lettere, numeri e i separatori consueti: questo comando lo
           innesca una rotta HTTP, e un nome di ramo che arriva da fuori non
           deve poter diventare un argomento della shell. */
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1) {
            $this->error('Nome di ramo non valido.');

            return self::FAILURE;
        }

        $passi = [
            'scarica' => ['git', 'fetch', '--depth', '1', 'origin', $branch],
            'allinea' => ['git', 'reset', '--hard', 'origin/'.$branch],
        ];

        if (! $this->option('skip-composer')) {
            $passi['dipendenze PHP'] = [
                $this->php(), '/usr/local/bin/composer', 'install',
                '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader',
            ];
        }

        /*
         * **Gli asset si compilano qui.** `public/build` non sta nel
         * repository — è generato, e versionarlo significherebbe un conflitto
         * a ogni ramo — quindi il codice appena arrivato porta le classi nuove
         * nei template ma non il CSS che le definisce. Senza questo passo il
         * markup cambia e la pagina resta identica: è successo, e sembrava che
         * il rilascio non fosse arrivato.
         *
         * `npm ci` solo quando il lock è cambiato: reinstallare duecento
         * megabyte di dipendenze a ogni rilascio costa minuti per niente.
         */
        if (! $this->option('skip-assets')) {
            if ($this->lockNodeCambiato()) {
                $passi['dipendenze JavaScript'] = ['npm', 'ci', '--no-audit', '--no-fund'];
            }

            $passi['compila gli asset'] = ['npm', 'run', 'build'];
        }

        foreach ($passi as $nome => $comando) {
            $this->line("→ {$nome}");

            $processo = new Process($comando, base_path(), timeout: 600);
            $processo->run();

            if (! $processo->isSuccessful()) {
                $this->error("«{$nome}» è fallito:");
                $this->line(trim($processo->getErrorOutput() ?: $processo->getOutput()));

                return self::FAILURE;
            }
        }

        /*
         * Da qui in poi si resta dentro Laravel: `Artisan::call` non apre un
         * processo nuovo e quindi non ripaga l'avvio del framework a ogni
         * comando. L'ordine conta — vedi la nota in testa alla classe.
         */
        foreach ([
            'migrate' => ['--force' => true],
            'db:seed' => ['--class' => 'RolesAndPermissionsSeeder', '--force' => true],
            'filament:assets' => [],
            'config:cache' => [],
            'route:cache' => [],
            'view:cache' => [],
            'queue:restart' => [],
        ] as $comando => $argomenti) {
            $this->line("→ {$comando}");

            if (Artisan::call($comando, $argomenti) !== self::SUCCESS) {
                $this->error("«{$comando}» è fallito:");
                $this->line(trim(Artisan::output()));

                return self::FAILURE;
            }
        }

        /* Il collegamento a `storage` è assoluto: quello creato altrove punta
           a un percorso che qui non esiste. Si ricrea solo se manca. */
        if (! is_link(public_path('storage'))) {
            Artisan::call('storage:link');
        }

        /* L'impronta del lock si scrive solo a rilascio riuscito: se qualcosa
           è fallito a metà, il prossimo giro reinstalla invece di dare per
           buono uno stato che non si conosce. */
        if (! $this->option('skip-assets') && is_dir(base_path('node_modules')) && is_file(base_path('package-lock.json'))) {
            file_put_contents(base_path('node_modules/.eventi-lock-hash'), md5_file(base_path('package-lock.json')));
        }

        $this->info('Rilasciato '.trim((new Process(['git', 'log', '-1', '--format=%h %s'], base_path()))->mustRun()->getOutput()));

        return self::SUCCESS;
    }
}
