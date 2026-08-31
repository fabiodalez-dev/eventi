<?php

declare(strict_types=1);

use App\Support\OpsAlerts;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

/*
 * Il backup di §16: database più storage, conservazione 30 giorni, e la
 * notifica di backup fallito — che §16 chiede esplicitamente, perché «un
 * backup che non gira e non avvisa è peggio di nessun backup».
 *
 * La destinazione è il disco locale del server: la shared hosting cPanel di
 * `docs/RUNBOOK.md` non ha alcun bucket S3 configurato. Il disco `s3` è
 * predisposto e commentato in tre punti — `destination.disks`,
 * `monitor_backups` e `BACKUP_DISKS` in `.env.example` — così accenderlo
 * costa una riga e non una rilettura di questo file.
 *
 * **Un backup non è valido finché non è stato provato un restore reale**:
 * la procedura sta in `docs/RUNBOOK.md`, sezione «Restore del database».
 */
return [

    'backup' => [
        /*
         * Il nome con cui i backup vengono raggruppati sul disco. È anche la
         * cartella: cambiarlo rende invisibili quelli già presenti.
         */
        'name' => env('BACKUP_NAME', 'eventi'),

        'source' => [
            'files' => [
                /*
                 * §16 chiede «database giornaliero, storage». Lo storage è
                 * `storage/app`: locandine caricate dai locali, anteprime
                 * social generate, allegati. Il **codice** non entra: sta in
                 * git, e ricopiarlo ogni giorno gonfierebbe l'archivio con
                 * l'unica cosa che è già al sicuro altrove.
                 */
                'include' => [
                    storage_path('app'),
                ],

                /*
                 * La cartella di destinazione dei backup e quella temporanea
                 * le esclude il pacchetto da sé — senza, ogni copia
                 * conterrebbe tutte le precedenti.
                 *
                 * Qui restano i file a metà caricamento di Livewire, che
                 * vivono sul disco predefinito (`FILESYSTEM_DISK`) e quindi in
                 * `private/` o in `public/` a seconda di come è configurato:
                 * si escludono entrambi, perché sono frammenti di moduli mai
                 * inviati e vengono cancellati da soli dopo qualche ora.
                 */
                'exclude' => [
                    storage_path('app/private/livewire-tmp'),
                    storage_path('app/public/livewire-tmp'),
                ],

                /*
                 * `storage/app/public` è un vero contenuto, non un collegamento
                 * da seguire: il collegamento è `public/storage`, che sta
                 * dall'altra parte e non è incluso.
                 */
                'follow_links' => false,

                'ignore_unreadable_directories' => true,

                /*
                 * I percorsi dentro l'archivio partono dalla radice del
                 * progetto invece che da `/home/fabiodal/...`: un restore su
                 * una macchina diversa non deve ricostruire l'albero del
                 * server.
                 */
                'relative_path' => base_path(),
            ],

            /*
             * La connessione di `config/database.php` in uso — `mariadb` in
             * sviluppo e in produzione (D3, D4). Dichiararla per nome e non
             * come costante segue l'ambiente senza toccare questo file.
             */
            'databases' => [
                env('DB_CONNECTION', 'mariadb'),
            ],
        ],

        /*
         * Il dump viene compresso prima di entrare nello zip: su un dump SQL
         * gzip fa gran parte del lavoro, e il livello di compressione dello
         * zip qui sotto scende di conseguenza.
         */
        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * Il dump è già compresso da gzip e le locandine sono JPEG e WebP:
             * comprimerli di nuovo al massimo costerebbe minuti di CPU su una
             * shared hosting per guadagnare qualche decina di kilobyte.
             */
            'compression_level' => 6,

            'filename_prefix' => '',

            /*
             * Dischi di destinazione, da `config/filesystems.php`. `local` è
             * `storage/app/private` sul server.
             *
             * Fuori dal server la copia va portata altrove — un backup che vive
             * solo sulla macchina che protegge non protegge da un guasto di
             * quella macchina. Quando esisterà un bucket:
             *
             *     BACKUP_DISKS=local,s3
             *
             * e le credenziali `AWS_*` già presenti in `.env.example`.
             */
            'disks' => array_map(
                trim(...),
                explode(',', (string) env('BACKUP_DISKS', 'local')),
            ),

            /*
             * Con più dischi, uno irraggiungibile non deve annullare la copia
             * riuscita sull'altro: il fallimento parziale viene notificato, ma
             * il backup locale resta.
             */
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * L'archivio contiene un dump con dati personali (§16): su un disco
         * condiviso vale la pena cifrarlo. Senza password non si cifra nulla,
         * ed è il comportamento predefinito in sviluppo.
         *
         * Attenzione: senza la password l'archivio non si riapre. Va conservata
         * dove si conservano i segreti, non accanto ai backup.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        /*
         * Dopo aver creato lo zip il pacchetto lo riapre e verifica che
         * contenga file. Costa un'apertura di archivio e trasforma «il file
         * esiste» in «il file si legge»: è metà della differenza fra un backup
         * e l'illusione di averne uno.
         */
        'verify_backup' => true,

        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
     * §16: la notifica che conta è quella di **fallimento**. Le tre notifiche
     * di successo restano dichiarate con un canale vuoto, non cancellate: un
     * messaggio ogni notte per dire che è andato tutto bene si smette di
     * leggere in una settimana, e da quel momento non si legge più nemmeno
     * quello che dice il contrario.
     *
     * `UnhealthyBackupWasFoundNotification` è il controllo che avvisa quando il
     * backup più recente è **troppo vecchio**: è ciò che copre il caso peggiore,
     * quello in cui il comando non parte affatto e quindi non fallisce mai.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Il destinatario è quello del pacchetto, che legge l'indirizzo qui
         * sotto: `OPS_ALERT_EMAIL`, lo stesso a cui arrivano gli allarmi di
         * stato e dei task schedulati. Tre indirizzi diversi per tre allarmi
         * dello stesso sistema si dimenticano uno alla volta.
         *
         * Se l'indirizzo è vuoto Laravel non invia nulla — una destinazione
         * falsa fa saltare il canale — quindi in sviluppo non parte niente
         * senza dover spegnere alcun interruttore.
         */
        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * Uno o più indirizzi separati da virgola. **Lista vuota, nessun
             * invio**: Laravel salta un canale senza destinazione, e il
             * pacchetto rifiuta invece una stringa vuota come indirizzo non
             * valido — è la ragione della forma ad array.
             */
            'to' => OpsAlerts::recipients(env('OPS_ALERT_EMAIL')),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * Il canale di `config/logging.php` su cui finisce il diario del backup.
     * `null` = quello predefinito, quindi `storage/logs/laravel.log`, dove
     * `docs/RUNBOOK.md` manda già a guardare.
     */
    'log_channel' => null,

    /*
     * La sorveglianza di §16: se il backup più recente ha più di un giorno,
     * oppure se l'insieme supera i due gigabyte, parte
     * `UnhealthyBackupWasFoundNotification`. È il controllo che accorge del
     * silenzio — un comando che non parte non fallisce, e senza questo non
     * avviserebbe nessuno.
     */
    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'eventi'),
            'disks' => array_map(
                trim(...),
                explode(',', (string) env('BACKUP_DISKS', 'local')),
            ),
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 2000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        /*
         * §16: conservazione **30 giorni**. Tutti i backup dei primi 30 giorni
         * restano; oltre non resta nulla, perché è quanto il piano chiede e
         * perché la shared hosting ha un disco condiviso e non elastico.
         *
         * I quattro periodi successivi sono a zero di proposito: la strategia
         * predefinita del pacchetto conserverebbe una copia settimanale per due
         * mesi, una mensile per quattro e una annuale per due anni — che è una
         * politica ragionevole, ma non è quella scritta nel piano.
         */
        'default_strategy' => [
            'keep_all_backups_for_days' => 30,

            'keep_daily_backups_for_days' => 0,

            'keep_weekly_backups_for_weeks' => 0,

            'keep_monthly_backups_for_months' => 0,

            'keep_yearly_backups_for_years' => 0,

            /*
             * Tetto di spazio: superato, i backup più vecchi spariscono prima
             * dei 30 giorni. Serve a non riempire il disco della shared
             * hosting, dove un disco pieno ferma anche il sito.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],

];
