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
        {--skip-assets : Salta il recupero di CSS e JavaScript compilati}';

    protected $description = 'Porta il server all\'ultimo commit del ramo e riallinea database, permessi e cache.';

    /**
     * Il PHP con cui gira l'applicazione, che sulla shared hosting **non è**
     * quello della shell: `composer` invocato senza dirglielo usa il PHP
     * predefinito (8.3) e rifiuta un lock che pretende 8.4.
     */
    private function php(): string
    {
        return $this->eseguibile('deploy.php_binary', [
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            PHP_BINARY,
        ], 'php');
    }

    /**
     * Il primo eseguibile che esiste davvero, con la configurazione che vince
     * su tutto: un'installazione fuori dall'ordinario si dichiara una volta in
     * `.env` invece di far indovinare il codice.
     *
     * @param  list<string>  $candidati
     */
    /**
     * L'ambiente da dare ai processi: il PATH corrente più le cartelle degli
     * eseguibili che abbiamo trovato.
     *
     * Serve perché un processo avviato da PHP non legge il profilo della shell
     * e il PATH che eredita può non contenere le cartelle giuste — su questa
     * shared hosting il PHP dell'applicazione sta fuori dai percorsi standard.
     *
     * @return array<string, string>
     */
    private function ambiente(): array
    {
        $cartella = dirname($this->php());

        $percorsi = array_filter([
            $cartella !== '.' && is_dir($cartella) ? $cartella : null,
            (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ]);

        return ['PATH' => implode(PATH_SEPARATOR, $percorsi)];
    }

    /**
     * Il primo eseguibile che esiste davvero, con la configurazione che vince
     * su tutto: un'installazione fuori dall'ordinario si dichiara una volta in
     * `.env` invece di far indovinare il codice.
     *
     * @param  list<string>  $candidati
     */
    private function eseguibile(string $chiave, array $candidati, string $ripiego): string
    {
        $configurato = config($chiave);

        if (is_string($configurato) && $configurato !== '') {
            return $configurato;
        }

        foreach ($candidati as $percorso) {
            if (is_executable($percorso)) {
                return $percorso;
            }
        }

        return $ripiego;
    }

    public function handle(): int
    {
        $branch = (string) $this->option('branch');

        /*
         * **Il nome del ramo arriva da una rotta HTTP**, quindi va trattato
         * come ostile.
         *
         * La whitelist di caratteri non basta da sola: `--upload-pack` è fatto
         * di sole lettere e trattini e la passerebbe, ma `git` lo leggerebbe
         * come OPZIONE invece che come ramo — ed è l'opzione con cui si fa
         * eseguire un comando arbitrario dall'altra parte. Lo stesso vale per
         * qualunque `-x`: un argomento che comincia con un trattino non è un
         * argomento, è una direttiva.
         *
         * Quindi tre condizioni, non una: caratteri consentiti, **niente
         * trattino iniziale**, e niente `..` — che nei riferimenti git è un
         * intervallo e nei percorsi una risalita.
         */
        $valido = preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) === 1
            && ! str_starts_with($branch, '-')
            && ! str_contains($branch, '..');

        if (! $valido) {
            $this->error('Nome di ramo non valido.');

            return self::FAILURE;
        }

        $passi = [
            'scarica' => ['git', 'fetch', '--depth', '1', 'origin', $branch],
            'allinea' => ['git', 'reset', '--hard', 'origin/'.$branch],
        ];

        if (! $this->option('skip-composer')) {
            $passi['dipendenze PHP'] = [
                $this->php(), $this->eseguibile('deploy.composer_binary', ['/usr/local/bin/composer', '/usr/bin/composer'], 'composer'), 'install',
                '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader',
            ];
        }

        /*
         * **Gli asset si SCARICANO, non si compilano.**
         *
         * `public/build` non sta nel ramo principale — è generato, e
         * versionarlo significherebbe un conflitto a ogni ramo — quindi il
         * codice appena arrivato porta le classi nuove nei template ma non il
         * CSS che le definisce. Senza questo passo il markup cambia e la
         * pagina resta identica: sembra che il rilascio non sia arrivato,
         * mentre è arrivato a metà.
         *
         * Compilarli qui non si può: su questa macchina Node cade all'avvio di
         * Vite — `Aborted (core dumped)` dentro `V8Platform::Initialize`, un
         * limite della shared hosting — e quando non cade impiega più di dieci
         * minuti. Li compila l'integrazione continua, che ha una macchina
         * vera, e li pubblica sul ramo `assets`: qui si scaricano e basta,
         * qualche centinaio di chilobyte.
         */
        if (! $this->option('skip-assets')) {
            $ramoAsset = config()->string('deploy.assets_branch');
            $archivio = storage_path('app/asset-rilascio.tar');

            $passi['scarica gli asset'] = ['git', 'fetch', '--depth', '1', 'origin', $ramoAsset];

            /*
             * `git archive` e non `git checkout`: il ramo degli asset ha i
             * file alla propria radice — è nato da un `git init` dentro
             * `public/build` — e un checkout li scriverebbe nella radice del
             * progetto, sparpagliando CSS e JavaScript accanto ad `artisan`.
             * L'archivio si estrae dove si vuole.
             */
            $passi['prepara gli asset'] = ['git', 'archive', '--format=tar', '--output='.$archivio, 'FETCH_HEAD'];
            $passi['installa gli asset'] = ['tar', '-xf', $archivio, '-C', public_path('build')];
        }

        /* La cartella deve esistere prima che `tar` ci estragga dentro: al
           primo rilascio su una macchina nuova non c'è. */
        if (! $this->option('skip-assets') && ! is_dir(public_path('build'))) {
            mkdir(public_path('build'), 0o755, recursive: true);
        }

        foreach ($passi as $nome => $comando) {
            $this->line("→ {$nome}");

            $processo = new Process($comando, base_path(), $this->ambiente(), timeout: 600);
            $processo->run();

            if (! $processo->isSuccessful()) {
                $this->error("«{$nome}» è fallito:");
                /* Il comando per esteso: senza, un fallimento con output vuoto
                   — un eseguibile non trovato — non dice niente su cosa si sia
                   provato a eseguire. */
                $this->line('  comando: '.implode(' ', $comando));
                $this->line(trim($processo->getErrorOutput() ?: $processo->getOutput()) ?: '  (nessun output)');

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

        /* L'archivio degli asset ha fatto il suo lavoro. */
        if (is_file(storage_path('app/asset-rilascio.tar'))) {
            @unlink(storage_path('app/asset-rilascio.tar'));
        }

        /* Il collegamento a `storage` è assoluto: quello creato altrove punta
           a un percorso che qui non esiste. Si ricrea solo se manca. */
        if (! is_link(public_path('storage'))) {
            Artisan::call('storage:link');
        }

        $this->info('Rilasciato '.trim((new Process(['git', 'log', '-1', '--format=%h %s'], base_path()))->mustRun()->getOutput()));

        return self::SUCCESS;
    }
}
