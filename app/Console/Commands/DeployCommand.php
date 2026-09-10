<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\HostingHtaccess;
use App\Support\ReleaseManifest;
use App\Support\ReleaseSnapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        {--skip-assets : Salta il recupero di CSS e JavaScript compilati}
        {--if-behind : Rilascia solo se il ramo ha commit nuovi E gli asset sono pronti}';

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
     * L'ambiente da dare ai processi.
     *
     * **Il rilascio parte da una richiesta HTTPS**, quindi da un processo del
     * server web — e quel processo non ha `HOME`. Composer lo pretende per
     * sapere dove tenere la cache e si RIFIUTA di partire senza:
     * «The HOME or COMPOSER_HOME environment variable must be set». Da
     * terminale non si vede, perché lì `HOME` c'è sempre; ed è proprio il
     * verso da cui il rilascio deve funzionare che ne è privo.
     *
     * Il `PATH` serve per la ragione gemella: un processo avviato da PHP non
     * legge il profilo della shell, e su questa shared hosting il PHP
     * dell'applicazione sta fuori dai percorsi standard.
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

        $home = $this->home();

        return array_filter([
            'PATH' => implode(PATH_SEPARATOR, $percorsi),
            'HOME' => $home,
            /* Esplicito oltre a `HOME`: se un giorno la home fosse in sola
               lettura, composer avrebbe comunque dove scrivere. */
            'COMPOSER_HOME' => $home === null ? null : $home.'/.composer',
        ]);
    }

    /**
     * Se ci sia davvero qualcosa di nuovo da rilasciare.
     *
     * **Due condizioni, non una.** Che il ramo sia avanti, e che gli asset di
     * QUEL commit siano già stati pubblicati: l'integrazione continua li
     * compila dopo i test, e un rilascio che partisse in mezzo porterebbe il
     * codice nuovo con i fogli di stile vecchi — il markup cambia, la pagina
     * resta com'era, e sembra che il rilascio non sia arrivato.
     *
     * Il legame fra i due lo dà il messaggio del commit sul ramo degli asset,
     * che porta lo SHA per cui sono stati compilati.
     */
    private function cEDaRilasciare(string $branch): bool
    {
        $ramoAsset = config('deploy.assets_branch');
        $ramoAsset = is_string($ramoAsset) && $ramoAsset !== '' ? $ramoAsset : 'assets';

        $fetch = new Process(['git', 'fetch', '--deepen=100', 'origin', $branch, $ramoAsset], base_path(), $this->ambiente(), timeout: 120);
        $fetch->run();

        if (! $fetch->isSuccessful()) {
            $this->error('Non riesco a interrogare il repository:');
            $this->line(trim($fetch->getErrorOutput()) ?: '  (nessun output)');

            return false;
        }

        $qui = $this->gitOutput(['git', 'rev-parse', 'HEAD']);
        $la = $this->gitOutput(['git', 'rev-parse', 'origin/'.$branch]);

        if ($qui === $la) {
            $this->line('Niente di nuovo: il server è già su '.mb_substr($qui ?? '', 0, 7).'.');

            return false;
        }

        /* Gli asset per il commit che stiamo per rilasciare. */
        $messaggio = $this->gitOutput(['git', 'log', '-1', '--format=%s', 'origin/'.$ramoAsset]);

        if ($la !== null && ! str_contains((string) $messaggio, $la)) {
            $this->line('Gli asset per '.mb_substr($la, 0, 7).' non sono ancora pronti: riprovo al prossimo giro.');

            return false;
        }

        return true;
    }

    /**
     * L'output di un comando git, o `null` se non è andato a buon fine.
     *
     * @param  list<string>  $comando
     */
    private function gitOutput(array $comando): ?string
    {
        $processo = new Process($comando, base_path(), $this->ambiente(), timeout: 60);
        $processo->run();

        return $processo->isSuccessful() ? trim($processo->getOutput()) : null;
    }

    /**
     * La home dell'utente che sta eseguendo, anche quando l'ambiente non la
     * dichiara.
     *
     * Si chiede al sistema chi siamo invece di dedurlo dai percorsi: un
     * progetto può stare ovunque, e risalire da `base_path()` indovinerebbe.
     */
    private function home(): ?string
    {
        $ambiente = getenv('HOME');

        if (is_string($ambiente) && $ambiente !== '' && is_dir($ambiente)) {
            return $ambiente;
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $utente = posix_getpwuid(posix_geteuid());

            /* `dir` c'è sempre quando la ricerca riesce: si verifica che la
               cartella esista davvero, non che il campo sia presente. */
            if (is_array($utente) && is_dir($utente['dir'])) {
                return $utente['dir'];
            }
        }

        return null;
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
        $lock = Cache::lock('deploy:execution', 3600);
        if (! $lock->get()) {
            $this->error('Un rilascio è già in corso.');

            return self::FAILURE;
        }
        try {
            return $this->release();
        } finally {
            $lock->release();
        }
    }

    private function release(): int
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

        if (! app()->environment('production')) {
            $this->line('Il deploy remoto è abilitato solo in produzione.');

            return $this->option('if-behind') ? self::SUCCESS : self::FAILURE;
        }
        if ($branch !== 'main' || $this->option('skip-assets')) {
            $this->error('Sono consentiti solo main e gli asset certificati dalla CI.');

            return self::FAILURE;
        }
        if (! $this->cEDaRilasciare($branch)) {
            return self::SUCCESS;
        }

        $sha = $this->gitOutput(['git', 'rev-parse', 'origin/main']);
        $assetSha = $this->gitOutput(['git', 'rev-parse', 'origin/'.config('deploy.assets_branch', 'assets')]);
        $manifest = json_decode($this->gitOutput(['git', 'show', $assetSha.':release.json']) ?? '', true);
        if (! is_string($sha) || ! is_string($assetSha) || ! ReleaseManifest::matches($manifest, $sha)) {
            $this->error('Manca il manifesto del rilascio verificato dalla CI. Nessun file modificato.');

            return self::FAILURE;
        }
        $dirty = $this->gitOutput(['git', 'status', '--porcelain', '--untracked-files=no']);
        $hostingOnly = $dirty === 'M public/.htaccess' && HostingHtaccess::isOnlyPhp84Handler(
            $this->gitOutput(['git', 'show', 'HEAD:public/.htaccess']) ?? '',
            (string) file_get_contents(public_path('.htaccess')),
        );
        // merge --ff-only leaves this hosting-generated addition untouched;
        // if upstream changes the same file, Git still refuses any overwrite.
        if ($dirty !== '' && ! $hostingOnly) {
            $this->error('Il server contiene modifiche tracciate: deploy interrotto per conservarle.');

            return self::FAILURE;
        }
        if (app()->isDownForMaintenance()) {
            $this->error('Sito già in manutenzione: serve verificare il precedente rilascio.');

            return self::FAILURE;
        }
        // Reserve room before creating the next archive, keeping ten releases in total.
        app(ReleaseSnapshots::class)->prune(storage_path('app/private/releases'), 9);
        $snapshot = storage_path('app/private/releases/'.gmdate('Ymd-His').'-'.substr($sha, 0, 12));
        if (! mkdir($snapshot, 0700, true)) {
            return self::FAILURE;
        }
        // Backups are taken before changing code or schema. Failure stops deployment.
        foreach ([
            [$this->php(), 'artisan', 'backup:run', '--only-db', '--disable-notifications'],
            ['git', 'archive', '--format=tar', '--output='.$snapshot.'/code.tar', 'HEAD'],
            ['tar', '-czf', $snapshot.'/build.tar.gz', '-C', public_path(), 'build'],
            [$this->php(), 'artisan', 'down', '--retry=60'],
        ] as $command) {
            $process = new Process($command, base_path(), $this->ambiente(), timeout: 600);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('Preparazione/backup fallito: '.$process->getErrorOutput().$process->getOutput());

                return self::FAILURE;
            }
        }

        $passi = [
            'allinea il commit verificato' => ['git', 'merge', '--ff-only', $sha],
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
        /*
         * Il valore predefinito sta QUI e non solo in `config/deploy.php`.
         *
         * `config()->string()` solleva se la chiave manca — e manca ogni
         * volta che si aggiunge un file di configurazione a un'installazione
         * che ha la configurazione in cache: la cache e' stata scritta
         * quando quel file non esisteva, e il rilascio la rigenera solo
         * DOPO aver usato questo valore. Il primo rilascio dopo
         * l'aggiunta falliva percio' con un 500 muto, prima ancora che il
         * controller potesse registrare il motivo.
         */
        $ramoAsset = config('deploy.assets_branch');
        $ramoAsset = is_string($ramoAsset) && $ramoAsset !== '' ? $ramoAsset : 'assets';
        $archivio = storage_path('app/asset-rilascio.tar');

        /*
         * `git archive` e non `git checkout`: il ramo degli asset ha i
         * file alla propria radice — è nato da un `git init` dentro
         * `public/build` — e un checkout li scriverebbe nella radice del
         * progetto, sparpagliando CSS e JavaScript accanto ad `artisan`.
         * L'archivio si estrae dove si vuole.
         */
        $passi['prepara gli asset'] = ['git', 'archive', '--format=tar', '--output='.$archivio, $assetSha];
        $passi['installa gli asset'] = ['tar', '-xf', $archivio, '-C', public_path('build')];

        /* La cartella deve esistere prima che `tar` ci estragga dentro: al
           primo rilascio su una macchina nuova non c'è. */
        if (! is_dir(public_path('build'))) {
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
         * **Ognuno in un processo PHP nuovo, e non e' uno spreco.**
         *
         * Prima erano `Artisan::call`, per non ripagare l'avvio del framework
         * sette volte. Il risparmio costava caro: questi comandi giravano
         * dentro il processo avviato PRIMA del `git reset`, con l'autoloader
         * di Composer gia' caricato in memoria — quello di prima. Le classi
         * arrivate con questo stesso rilascio non esistevano per lui, quindi
         * la scoperta delle pagine di Filament non le trovava e `route:cache`
         * congelava una mappa senza di esse.
         *
         * Il risultato era che **ogni classe nuova non veniva applicata dal
         * rilascio che la portava**: compariva al giro successivo, o dopo un
         * intervento a mano. Visto succedere con la pagina di configurazione
         * della posta, che rispondeva 404 mentre il suo file era li' sul
         * disco.
         *
         * Sette avvii di Laravel sono un paio di secondi. Un rilascio che non
         * applica cio' che porta e' un rilascio che va rifatto a mano.
         *
         * L'ordine conta — vedi la nota in testa alla classe.
         */
        /*
         * **Una lista di coppie e non una mappa comando => argomenti.**
         *
         * Le chiavi di un array sono uniche, e `db:seed` va eseguito due volte
         * con classi diverse: con la mappa la seconda voce avrebbe sovrascritto
         * la prima in silenzio, e i ruoli non sarebbero piu' stati seminati.
         *
         * L'ordine conta — vedi la nota in testa alla classe.
         *
         * @var list<array{string, list<string>}> $artisan
         */
        $artisan = [
            ['migrate', ['--force']],
            ['db:seed', ['--class=RolesAndPermissionsSeeder', '--force']],
            /*
             * Il registro dei cookie: e' il contenuto di un'informativa
             * legale, e nasce vuoto se nessuno lo semina. Il seeder e'
             * idempotente — `updateOrCreate` sulla coppia finalita'/nome — e
             * non tocca le righe aggiunte dal pannello, che nel suo elenco non
             * compaiono.
             */
            ['db:seed', ['--class=CookieDeclarationSeeder', '--force']],
            /*
             * I comandi schedulati cambiano coi rilasci — e non solo per mano
             * nostra: un pacchetto nuovo puo' registrarne uno dal proprio
             * ServiceProvider, come fa `pxlrbt/filament-excel` con
             * `filament-excel:prune`. Senza questa sincronizzazione quel
             * comando gira senza che nessuno lo sorvegli, ed e' proprio il
             * caso in cui `schedule-monitor` non serve a niente: la coda
             * potrebbe morire in silenzio e il controllo di salute resterebbe
             * verde.
             */
            ['schedule-monitor:sync', []],
            ['filament:assets', []],
            ['config:cache', []],
            ['route:cache', []],
            ['view:cache', []],
            ['queue:restart', []],
            ['deploy:verify', []],
        ];

        foreach ($artisan as [$comando, $argomenti]) {
            $this->line("→ {$comando}");

            $processo = new Process(
                [$this->php(), 'artisan', $comando, ...$argomenti],
                base_path(),
                $this->ambiente(),
                timeout: 600,
            );
            $processo->run();

            if (! $processo->isSuccessful()) {
                $this->error("«{$comando}» è fallito:");
                $this->line(trim($processo->getErrorOutput() ?: $processo->getOutput()) ?: '  (nessun output)');

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

        $status = ['sha' => $sha, 'deployed_at' => gmdate(DATE_ATOM)];
        $statusFile = storage_path('app/private/release-status.json');
        if (file_put_contents($statusFile.'.tmp', json_encode($status, JSON_THROW_ON_ERROR)) === false || ! rename($statusFile.'.tmp', $statusFile)) {
            $this->error('Impossibile registrare il rilascio. Il sito resta in manutenzione.');

            return self::FAILURE;
        }
        $up = new Process([$this->php(), 'artisan', 'up'], base_path(), $this->ambiente(), timeout: 60);
        $up->run();
        if (! $up->isSuccessful()) {
            return self::FAILURE;
        }

        $this->info('Rilasciato '.trim((new Process(['git', 'log', '-1', '--format=%h %s'], base_path()))->mustRun()->getOutput()));

        return self::SUCCESS;
    }
}
