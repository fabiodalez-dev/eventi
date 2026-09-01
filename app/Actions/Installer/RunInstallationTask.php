<?php

declare(strict_types=1);

namespace App\Actions\Installer;

use App\Enums\InstallerStep;
use App\Enums\InstallerTask;
use App\Enums\UserRole;
use App\Exceptions\Installer\TaskFailed;
use App\Models\City;
use App\Models\User;
use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallerState;
use App\Services\Installer\RequirementsChecker;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Esegue **una** operazione della checklist di installazione (D42, punto 3).
 *
 * Una alla volta e non tutte insieme: un passo monolitico che scrive il
 * `.env`, migra trentasette tabelle, semina e crea l'amministratore è un passo
 * che su un database lento incontra il limite di tempo di PHP — e quando lo
 * incontra, nessuno sa a che punto era arrivato. Qui ogni operazione è un POST
 * proprio e **idempotente**: riprovarne una già riuscita non rompe niente,
 * quindi un ricaricamento o un tentativo in più sono innocui.
 *
 * I comandi girano **in-process con `Artisan::call()`**, mai lanciando una
 * shell. Due ragioni indipendenti che portano alla stessa scelta: la password
 * del database non deve comparire in `ps`, e su una shared hosting
 * `proc_open()` può essere disabilitata.
 */
class RunInstallationTask
{
    public function __construct(
        private readonly InstallerState $state,
        private readonly EnvWriter $env,
        private readonly DatabaseInspector $inspector,
        private readonly RequirementsChecker $requirements,
    ) {}

    /**
     * @return string|null il testo di un avviso se l'operazione è riuscita
     *                     solo in parte, `null` se è filata liscia
     *
     * @throws TaskFailed
     */
    public function __invoke(InstallerTask $task): ?string
    {
        /*
         * Ogni operazione è una richiesta a sé, e la configurazione di Laravel
         * riparte ogni volta dal `.env` di prima: le coordinate del database
         * appena dichiarate vanno rimesse a ogni giro, o `migrate` andrebbe a
         * bussare al database sbagliato.
         */
        $this->applyRuntimeConfiguration();

        /*
         * Solo le ultime due operazioni possono riuscire a metà — un
         * collegamento che non si crea, una cache che non si scrive — e sono
         * le sole che restituiscono un avviso. Le altre o vanno a buon fine o
         * sollevano `TaskFailed`: non esiste una migrazione «quasi fatta».
         */
        switch ($task) {
            case InstallerTask::Env:
                $this->writeEnv();
                break;
            case InstallerTask::Migrate:
                $this->migrate();
                break;
            case InstallerTask::VerifyTables:
                $this->verifyTables();
                break;
            case InstallerTask::Seed:
                $this->seed();
                break;
            case InstallerTask::Content:
                return $this->createContent();
            case InstallerTask::StorageLink:
                return $this->linkStorage();
            case InstallerTask::Cache:
                return $this->cacheConfiguration();
        }

        return null;
    }

    /**
     * Le variabili che finiscono nel `.env`: tredici risposte, due valori
     * generati, e nient'altro.
     *
     * Le altre ~76 di `.env.example` non compaiono qui e restano al valore del
     * modello, che in questo progetto **è già** la configurazione di produzione
     * corretta: ogni integrazione esterna nasce spenta, «vuoto = spento».
     *
     * @return array<string, string>
     */
    public function environmentValues(): array
    {
        $database = $this->state->get(InstallerStep::Database);
        $application = $this->state->get(InstallerStep::Application);
        $city = $this->state->get(InstallerStep::City);
        $admin = $this->state->get(InstallerStep::Admin);

        $adminEmail = $this->text($admin, 'email');

        $values = [
            'APP_NAME' => $this->text($application, 'app_name'),
            'APP_ENV' => 'production',
            'APP_KEY' => config()->string('app.key'),
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim($this->text($application, 'app_url'), '/'),

            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => $this->text($database, 'db_host'),
            'DB_PORT' => $this->text($database, 'db_port'),
            'DB_DATABASE' => $this->text($database, 'db_database'),
            'DB_USERNAME' => $this->text($database, 'db_username'),
            'DB_PASSWORD' => $this->text($database, 'db_password'),

            'MAIL_MAILER' => $this->text($application, 'mail_mailer', 'log'),
            'MAIL_FROM_ADDRESS' => $this->text($application, 'mail_from_address', $adminEmail),

            'CITY_DEFAULT_SLUG' => $this->text($city, 'slug'),

            /*
             * Il motore immagini non si chiede: lo si guarda. Con Imagick
             * assente ma GD presente la pipeline resta in piedi e perde HEIC e
             * AVIF — che è un avviso già dato, non una domanda da fare.
             */
            'IMAGE_DRIVER' => $this->requirements->imageDriver(),

            /*
             * Vuota, nessun allarme parte (RUNBOOK): un sistema appena
             * installato che tace i propri guasti è il default sbagliato.
             */
            'OPS_ALERT_EMAIL' => $this->text($application, 'ops_alert_email', $adminEmail),

            /* Generata, non chiesta: senza, `/stato` risponde 404 a chiunque. */
            'OPS_HEALTH_TOKEN' => 'base64:'.base64_encode(random_bytes(32)),
        ];

        if ($values['MAIL_MAILER'] === 'smtp') {
            $values['MAIL_HOST'] = $this->text($application, 'mail_host');
            $values['MAIL_PORT'] = $this->text($application, 'mail_port');
            $values['MAIL_FROM_NAME'] = $values['APP_NAME'];

            /*
             * Utente, password e schema si scrivono **solo se ci sono**. Il
             * modello li lascia a `null`, che è ciò che fa dire a Laravel
             * «nessuna autenticazione» e «negozia tu la cifratura»: una stringa
             * vuota scritta al loro posto è un'altra cosa, e su un server che
             * richiede STARTTLS sulla 587 sarebbe la differenza fra una posta
             * che parte e una che no.
             */
            foreach (['mail_username' => 'MAIL_USERNAME', 'mail_password' => 'MAIL_PASSWORD'] as $field => $key) {
                $value = $this->text($application, $field);

                if ($value !== '') {
                    $values[$key] = $value;
                }
            }

            if ($this->text($application, 'mail_scheme') === 'smtps') {
                $values['MAIL_SCHEME'] = 'smtps';
            }
        }

        return $values;
    }

    private function writeEnv(): void
    {
        try {
            $this->env->write($this->environmentValues());
        } catch (Throwable $exception) {
            throw new TaskFailed(
                __('installer.tasks.env.failed'),
                $exception->getMessage(),
                'chmod 0664 .env',
            );
        }

        $this->applyRuntimeConfiguration();
    }

    private function migrate(): void
    {
        try {
            $status = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            throw new TaskFailed(__('installer.tasks.migrazioni.failed'), $exception->getMessage());
        }

        if ($status !== 0) {
            throw new TaskFailed(__('installer.tasks.migrazioni.failed'), Artisan::output());
        }
    }

    /**
     * Le migrazioni hanno detto di essere andate bene: qui si controlla che sia
     * vero. L'elenco delle tabelle attese si deriva dai file di migrazione, non
     * da un elenco scritto a mano — che mentirebbe alla prima migrazione nuova.
     */
    private function verifyTables(): void
    {
        $missing = $this->inspector->missingTables();

        if ($missing !== []) {
            throw new TaskFailed(
                __('installer.tasks.verifica-tabelle.failed', [
                    'tables' => implode(', ', array_slice($missing, 0, 5)),
                    'count' => (string) count($missing),
                ]),
                'tabelle mancanti: '.implode(', ', $missing),
                'php artisan migrate --force',
            );
        }
    }

    private function seed(): void
    {
        try {
            $status = Artisan::call('db:seed', [
                '--class' => ProductionSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            throw new TaskFailed(__('installer.tasks.dati-di-base.failed'), $exception->getMessage());
        }

        if ($status !== 0) {
            throw new TaskFailed(__('installer.tasks.dati-di-base.failed'), Artisan::output());
        }
    }

    /**
     * La città e l'amministratore: non sono seed, sono le risposte del wizard.
     * Si scrivono con `City::create()` e `User::create()` diretti, senza
     * factory — le factory chiamano `fake()`, che in produzione non esiste.
     */
    private function createContent(): ?string
    {
        try {
            $city = $this->createCity();
            $this->createAdministrator();
        } catch (Throwable $exception) {
            throw new TaskFailed(__('installer.tasks.citta-e-amministratore.failed'), $exception->getMessage());
        }

        return $city->slug === $this->state->value(InstallerStep::City, 'slug')
            ? null
            : __('installer.tasks.citta-e-amministratore.slug_changed', ['slug' => $city->slug]);
    }

    private function createCity(): City
    {
        $data = $this->state->get(InstallerStep::City);
        $slug = $this->text($data, 'slug');

        $existing = City::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $city = City::create([
            'name' => $this->text($data, 'name'),
            'province_code' => Str::upper($this->text($data, 'province_code')),
            'province_name' => $this->text($data, 'province_name'),
            'region' => $this->text($data, 'region'),
            'timezone' => $this->text($data, 'timezone', 'Europe/Rome'),
            'center_lat' => $this->text($data, 'center_lat'),
            'center_lng' => $this->text($data, 'center_lng'),
            'radius_km' => (int) $this->text($data, 'radius_km', '30'),
            'is_active' => true,
            'launched_at' => Carbon::now(),
        ]);

        /*
         * Lo slug scelto a mano va rimesso con una scrittura diretta: il trait
         * `HasSlug` lo rigenera dal nome sia in creazione sia in aggiornamento,
         * quindi passarlo negli attributi non serve a niente e ripassarlo con
         * `save()` verrebbe sovrascritto una seconda volta.
         */
        if ($slug !== '' && $city->slug !== $slug && ! City::query()->where('slug', $slug)->exists()) {
            City::query()->whereKey($city->getKey())->update(['slug' => $slug]);
            $city->refresh();
        }

        return $city;
    }

    private function createAdministrator(): User
    {
        $data = $this->state->get(InstallerStep::Admin);
        $email = $this->text($data, 'email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::create([
                'name' => $this->text($data, 'name'),
                'email' => $email,
                'password' => $this->text($data, 'password'),
            ]);

            /*
             * Nessuna email di verifica da mandare: chi ha appena digitato
             * quell'indirizzo in questa pagina lo ha già dimostrato suo, e su
             * un sistema dove la posta non è ancora configurata la verifica
             * sarebbe una porta chiusa a chiave con la chiave dentro.
             */
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
        }

        if (! $user->hasRole(UserRole::SuperAdmin->value)) {
            $user->assignRole(UserRole::SuperAdmin->value);
        }

        return $user;
    }

    /**
     * `public/storage` è un symlink, e su parecchie shared hosting `symlink()`
     * è disabilitata. Non è un motivo per fermare l'installazione: è un motivo
     * per dirlo e dare il comando.
     */
    private function linkStorage(): ?string
    {
        if ($this->storageLinkExists()) {
            return null;
        }

        try {
            $status = Artisan::call('storage:link');
        } catch (Throwable) {
            $status = 1;
        }

        return $status === 0 && $this->storageLinkExists()
            ? null
            : __('installer.tasks.collegamento-storage.warning');
    }

    /**
     * `clearstatcache()` non è pignoleria: PHP tiene in memoria l'esito delle
     * `stat()` già fatte, e la prima domanda di questo metodo viene posta
     * *prima* che `storage:link` crei il collegamento. Senza svuotare quella
     * cache, la seconda domanda riceverebbe la risposta della prima e l'avviso
     * partirebbe anche a collegamento creato.
     *
     * @phpstan-impure il risultato dipende dal filesystem, che `storage:link`
     *                 cambia fra una chiamata e l'altra
     */
    private function storageLinkExists(): bool
    {
        $link = public_path('storage');

        clearstatcache(true, $link);

        return is_link($link) || is_dir($link);
    }

    private function cacheConfiguration(): ?string
    {
        try {
            $status = Artisan::call('config:cache');
        } catch (Throwable) {
            $status = 1;
        }

        return $status === 0 ? null : __('installer.tasks.cache.warning');
    }

    /**
     * Rimette in `config()` le coordinate del database appena dichiarate.
     *
     * Solo se sono davvero cambiate: `DB::purge()` chiude la connessione
     * aperta, e chiuderla quando non serve significa buttare via una
     * transazione in corso per niente.
     */
    private function applyRuntimeConfiguration(): void
    {
        $data = $this->state->get(InstallerStep::Database);

        if ($data === []) {
            return;
        }

        $target = [
            'host' => $this->text($data, 'db_host'),
            'port' => $this->text($data, 'db_port'),
            'database' => $this->text($data, 'db_database'),
            'username' => $this->text($data, 'db_username'),
            'password' => $this->text($data, 'db_password'),
        ];

        $connection = 'mariadb';
        $changed = false;

        foreach ($target as $key => $value) {
            if ((string) config('database.connections.'.$connection.'.'.$key) !== $value) {
                $changed = true;
            }

            config(['database.connections.'.$connection.'.'.$key => $value]);
        }

        config(['database.default' => $connection]);

        if ($changed) {
            DB::purge($connection);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }
}
