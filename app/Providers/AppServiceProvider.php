<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RevokeInvalidFcmToken;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\ImportSource;
use App\Models\MobileAuthChallenge;
use App\Models\Organizer;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Report;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Observers\CategoryObserver;
use App\Observers\CityObserver;
use App\Observers\EventObserver;
use App\Observers\EventOccurrenceObserver;
use App\Observers\TagObserver;
use App\Observers\TicketingObserver;
use App\Observers\TicketTierObserver;
use App\Observers\VenueObserver;
use App\Policies\CategoryPolicy;
use App\Policies\CityPolicy;
use App\Policies\EventOccurrencePolicy;
use App\Policies\EventPolicy;
use App\Policies\FollowPolicy;
use App\Policies\ImportSourcePolicy;
use App\Policies\PagePolicy;
use App\Policies\RedirectPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SavedEventPolicy;
use App\Policies\ScheduledNotificationPolicy;
use App\Policies\SponsorshipPolicy;
use App\Policies\TagPolicy;
use App\Policies\TicketTierPolicy;
use App\Policies\UserPolicy;
use App\Policies\VenueApplicationPolicy;
use App\Policies\VenuePolicy;
use App\Services\Geo\AddressGeocoder;
use App\Services\Geo\GeoQueryInterface;
use App\Services\Geo\MariaDbGeoQuery;
use App\Services\Geo\NominatimGeocoder;
use App\Services\Http\SafeWebPushFactory;
use App\Services\Import\DnsHostResolver;
use App\Services\Import\HostResolver;
use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallLock;
use App\Services\Installer\RequirementsChecker;
use App\Support\Api\MobileOpenApiDocument;
use App\Support\Consent;
use App\Support\Csp;
use App\Support\CurrentCity;
use App\Support\CurrentFollows;
use App\Support\CurrentSaves;
use App\Support\DateFormatter;
use App\Support\Lang\DatabaseOverrideLoader;
use App\Support\SecurityLog;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /* Telescope e' una dipendenza di sviluppo: in produzione, dove
           Composer installa con --no-dev, questa condizione evita qualunque
           riferimento a classi non presenti. */
        if (class_exists(TelescopeApplicationServiceProvider::class)
            && filter_var(config('telescope.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Le query geospaziali passano tutte da qui: cambiare motore di
        // database costa questa riga più una implementazione dell'interfaccia.
        $this->app->bind(GeoQueryInterface::class, MariaDbGeoQuery::class);

        /*
         * Il nonce della CSP: **uno per risposta**, quindi singleton.
         *
         * Senza questa riga ogni `app(Csp::class)` costruirebbe un oggetto
         * nuovo con un numero nuovo, e l'intestazione prometterebbe un valore
         * che nella pagina non c'è: tutti gli script bloccati, su tutte le
         * pagine, in una forma che in sviluppo non si nota perché lì la
         * politica si può spegnere.
         *
         * `scoped` e non `singleton`: sotto Octane il secondo sopravviverebbe
         * fra una richiesta e l'altra, e un nonce riusato non è un nonce.
         */
        $this->app->scoped(Csp::class);

        /*
         * I testi delle email si possono riscrivere dal pannello.
         *
         * Si sostituisce il caricatore, non il traduttore: cosi' Laravel
         * continua a fare tutto il resto — scelta della lingua, sostituzione
         * delle variabili, plurali — e noi ci limitiamo a sovrapporre le
         * righe salvate sopra quelle del file. Va in `register` e non in
         * `boot` perche' il traduttore viene costruito prestissimo, e a quel
         * punto il caricatore dev'essere gia' il nostro.
         */
        $this->app->extend('translation.loader', static fn (
            FileLoader $vecchio,
            Application $app
        ): DatabaseOverrideLoader => new DatabaseOverrideLoader(
            $app->make('files'),
            $app->langPath(),
        ));

        // La risoluzione dei nomi che `ImportUrlGuard` interroga prima di
        // scaricare un calendario: dietro un'interfaccia perché la difesa
        // contro gli indirizzi interni si possa verificare senza DNS.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // La traduzione degli indirizzi in coordinate: dietro un'interfaccia
        // perche' i test non debbano interrogare OpenStreetMap.
        $this->app->bind(AddressGeocoder::class, NominatimGeocoder::class);

        // La città della richiesta si carica una volta sola, e con lei il fuso
        // in cui vanno lette tutte le date mostrate a chi legge.
        $this->app->scoped(CurrentCity::class);

        // La scelta sul consenso (§16): il cookie si legge una volta per
        // richiesta, e la scelta appena espressa vale già per la risposta che
        // la registra.
        $this->app->scoped(Consent::class);

        // Le date già in agenda della persona collegata: una lettura per
        // richiesta, condivisa da tutti i cuori disegnati nella pagina.
        $this->app->scoped(CurrentSaves::class);
        $this->app->scoped(CurrentFollows::class);

        $this->app->scoped(
            DateFormatter::class,
            fn ($app): DateFormatter => DateFormatter::forTimezone($app->make(CurrentCity::class)->timezone()),
        );

        /*
         * L'installer (D42). I quattro servizi ricevono qui i percorsi su cui
         * lavorano invece di chiamare `base_path()` dentro di sé: è ciò che
         * permette ai test di farli scrivere su una directory temporanea
         * invece che sul `.env` della macchina che li sta eseguendo.
         */
        $this->app->singleton(
            EnvWriter::class,
            fn (): EnvWriter => new EnvWriter(base_path('.env'), base_path('.env.example')),
        );

        $this->app->singleton(
            InstallLock::class,
            fn (): InstallLock => new InstallLock(storage_path('app/private/install.lock')),
        );

        $this->app->singleton(
            DatabaseInspector::class,
            fn (): DatabaseInspector => new DatabaseInspector(database_path('migrations')),
        );

        $this->app->singleton(
            RequirementsChecker::class,
            fn ($app): RequirementsChecker => new RequirementsChecker(base_path(), $app->make(EnvWriter::class)),
        );
    }

    public function boot(): void
    {
        $this->app->when(WebPushChannel::class)
            ->needs(WebPush::class)
            ->give(fn () => (new SafeWebPushFactory($this->app))->make());

        EventFacade::listen(NotificationFailed::class, RevokeInvalidFcmToken::class);

        // Session logins emit Login/Failed. API password checks and HTTP throttles log at their own entry points.
        EventFacade::listen(Login::class, static function (Login $event): void {
            SecurityLog::scrivi('accesso_riuscito', $event->user instanceof User ? $event->user : null);
        });
        EventFacade::listen(Failed::class, static function (Failed $evento): void {
            SecurityLog::accessoFallito($evento->credentials['email'] ?? null);
        });
        EventFacade::listen(Lockout::class, static function (): void {
            SecurityLog::blocco();
        });
        EventFacade::listen(PasswordReset::class, static function (PasswordReset $evento): void {
            if ($evento->user instanceof User) {
                $evento->user->tokens()->delete();
                MobileAuthChallenge::query()->where('user_id', $evento->user->getKey())->delete();
            }
            SecurityLog::scrivi('password_reimpostata', $evento->user instanceof User ? $evento->user : null);
        });

        /*
         * `@cspNonce` su un tag `<script>` (§16).
         *
         * Una direttiva e non `{{ }}` scritto a mano perché il valore giusto è
         * «l'attributo intero, oppure niente»: dove la politica non si applica
         * non deve comparire un `nonce=""` vuoto, che sarebbe un nonce
         * sbagliato invece di nessun nonce. Vedi `App\Support\Csp`.
         */
        Blade::directive('cspNonce', static fn (): string => '<?php echo app(\App\Support\Csp::class)->attribute(); ?>');

        /*
         * Limite di frequenza dei moduli pubblici (§14.7): cinque invii l'ora
         * per indirizzo IP. È la seconda barriera dopo il campo esca — la
         * prima ferma i robot generici, questa ferma chi insiste.
         */
        RateLimiter::for('public-forms', static fn (Request $request): Limit => Limit::perHour(5)->by($request->ip() ?? 'sconosciuto'));

        /*
         * Il consenso (§16) ha un limite proprio, e più largo di quello dei
         * moduli pubblici: cambiare idea sulle proprie preferenze non è un
         * invio di contenuti, ed è un diritto — cinque volte l'ora sarebbe un
         * modo elegante di rendere difficile la revoca. Trenta l'ora ferma chi
         * riempie il registro senza mai ostacolare chi decide davvero.
         */
        RateLimiter::for('consent', static fn (Request $request): Limit => Limit::perHour(30)->by($request->ip() ?? 'sconosciuto'));

        /*
         * Le misure delle campagne sponsorizzate. Piu' largo del consenso
         * perche' scatta a ogni card che entra nello schermo — una pagina
         * scorsa per intero ne manda parecchie — e comunque stretto abbastanza
         * da fermare uno script. Il tetto per SINGOLA campagna, che e' quello
         * che protegge la fattura, sta nel controller.
         */
        RateLimiter::for('sponsorship-metrics', static fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip() ?? 'sconosciuto'));

        /*
         * Limiti dell'API (§13.4): 60 richieste al minuto per chi non è
         * autenticato, contate per indirizzo IP, 120 per chi lo è, contate per
         * utente. Il conteggio per utente e non per IP è ciò che evita che una
         * rete aziendale dietro un solo indirizzo si blocchi da sola.
         */
        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user('sanctum');

            $installation = $request->header('X-Installation-ID');
            $anonymousKey = is_string($installation)
                && preg_match('/^[A-Za-z0-9._:-]{16,64}$/', $installation) === 1
                    ? 'installazione:'.$installation
                    : 'ip:'.($request->ip() ?? 'sconosciuto');

            return $user === null
                ? Limit::perMinute(config()->integer('api.rate_limit.anonymous'))->by($anonymousKey)
                : Limit::perMinute(config()->integer('api.rate_limit.authenticated'))->by('utente:'.$user->getAuthIdentifier());
        });

        /*
         * Accesso, registrazione e reimpostazione password hanno un limite a
         * parte (§16). La chiave unisce indirizzo IP ed email: senza l'email,
         * chi prova mille password su un solo account resterebbe dentro il
         * limite di un IP condiviso; senza l'IP, basterebbe cambiare email a
         * ogni tentativo.
         */
        RateLimiter::for('api-auth', static function (Request $request): array {
            $email = $request->input('email');
            $indirizzo = $request->ip() ?? 'sconosciuto';
            $conto = is_string($email) ? mb_strtolower($email) : '';

            return [
                /* La coppia: ferma chi prova molte password su un account da
                   un indirizzo solo. */
                Limit::perMinute(config()->integer('api.rate_limit.auth'))->by($indirizzo.'|'.$conto),

                /*
                 * IL BERSAGLIO, indipendentemente da chi prova.
                 *
                 * Il limite sulla coppia vale per la COPPIA: con un pool di
                 * mille indirizzi — e si affittano a poco — chi attacca
                 * ottiene mille volte quel tetto sullo stesso account, e il
                 * conto non se ne accorge. Questa riga conta i tentativi
                 * subiti dall'account, che è la grandezza che interessa a chi
                 * quell'account lo possiede.
                 */
                Limit::perMinutes(15, config()->integer('api.rate_limit.attempts_per_account'))
                    ->by('conto:'.$conto),
            ];
        });

        /*
         * GLI INDIRIZZI CHE MANDANO UN'EMAIL DOVE DICI TU, contati sul solo
         * indirizzo di chi chiama: iscrizione, collegamento di accesso,
         * password dimenticata.
         *
         * `api-auth` e `account-auth` contano su indirizzo **più email**, e
         * chi manda messaggi in serie cambia email a ogni giro: per lui quel
         * tetto non esiste. Questo lo conta su ciò che non può cambiare a
         * costo zero, e protegge la cosa che si perde davvero — su hosting
         * condiviso la reputazione SMTP è del sito, non di chi ne abusa.
         *
         * Sta su un limitatore a sé e non dentro gli altri di proposito: un
         * tetto per indirizzo sull'**accesso** chiuderebbe fuori un ufficio
         * dietro un solo IP, che è il motivo per cui gli altri due la chiave
         * la compongono con l'email.
         */
        RateLimiter::for('outbound-email', static fn (Request $request): Limit => Limit::perHour(
            config()->integer('api.rate_limit.outbound_emails_per_hour'),
        )->by('invii:'.($request->ip() ?? 'sconosciuto')));

        /*
         * Accesso, registrazione e collegamento di accesso del **sito** (§16).
         * Stesso ragionamento del limite dell'API: la chiave unisce indirizzo
         * IP ed email, perché senza l'email chi prova mille password su un
         * solo account resterebbe dentro il limite di un IP condiviso, e senza
         * l'IP basterebbe cambiare email a ogni tentativo.
         */
        RateLimiter::for('account-auth', static function (Request $request): array {
            $email = $request->input('email');
            $indirizzo = $request->ip() ?? 'sconosciuto';
            $conto = is_string($email) ? mb_strtolower($email) : '';

            return [
                /* La coppia: ferma chi prova molte password su un account da
                   un indirizzo solo. */
                Limit::perMinute(config()->integer('api.rate_limit.auth'))->by($indirizzo.'|'.$conto),

                /*
                 * IL BERSAGLIO, indipendentemente da chi prova.
                 *
                 * Il limite sulla coppia vale per la COPPIA: con un pool di
                 * mille indirizzi — e si affittano a poco — chi attacca
                 * ottiene mille volte quel tetto sullo stesso account, e il
                 * conto non se ne accorge. Questa riga conta i tentativi
                 * subiti dall'account, che è la grandezza che interessa a chi
                 * quell'account lo possiede.
                 */
                Limit::perMinutes(15, config()->integer('api.rate_limit.attempts_per_account'))
                    ->by('conto:'.$conto),
            ];
        });

        // Le colonne morph (`follows.followable_type`, `reports.reportable_type`,
        // `scheduled_notifications.notifiable_type`) contengono alias brevi e non
        // nomi di classe: i dati non devono dipendere dal namespace PHP.
        Relation::enforceMorphMap([
            'sponsorship_grant' => SponsorshipGrant::class,
            'booking' => Booking::class,
            'admission_ticket' => AdmissionTicket::class,
            'city' => City::class,
            'venue' => Venue::class,
            'organizer' => Organizer::class,
            'category' => Category::class,
            'tag' => Tag::class,
            'event' => Event::class,
            'event_occurrence' => EventOccurrence::class,
            'user' => User::class,
        ]);

        // `business_date` ed `effective_ends_at` sono calcolate dall'observer a
        // ogni salvataggio; cambiare categoria o città a un evento ricalcola le
        // occorrenze già salvate.
        EventOccurrence::observe(EventOccurrenceObserver::class);
        Event::observe(EventObserver::class);
        Event::observe(TicketingObserver::class);
        EventOccurrence::observe(TicketingObserver::class);

        // Quando un evento ha delle fasce di prezzo, sono loro a dettare
        // `price_min`/`price_max`: il filtro «fino a 10 €» è una WHERE su
        // quelle colonne, e un minimo calcolato al render ne resterebbe fuori.
        TicketTier::observe(TicketTierObserver::class);

        // `venues.location` non si scrive a mano: è il punto geometrico
        // derivato da `lat`/`lng`, ed è ciò su cui gira l'indice spaziale.
        Venue::observe(VenueObserver::class);

        /*
         * Uno slug che cambia è un indirizzo che muore, e nessuno se ne
         * accorge: chi rinomina vede la pagina nuova. Questi observer scrivono
         * la riga in `redirects`, che `RedirectDalDatabase` rilegge sui 404.
         *
         * `Event` e `Venue` sono già osservati qui sopra e fanno lo stesso al
         * loro interno; `City` è il caso con il jolly, perché il suo slug è il
         * prefisso di tutte le rotte del sito pubblico.
         */
        Category::observe(CategoryObserver::class);
        Tag::observe(TagObserver::class);
        City::observe(CityObserver::class);

        /*
         * La documentazione OpenAPI (`/docs/api`) prende titolo e descrizione
         * da `lang/it`, non dalla configurazione: sono testi, e i testi stanno
         * in un posto solo (§2 delle convenzioni).
         */
        Scramble::configure()->withDocumentTransformers(static function (OpenApi $document): void {
            $document->info->title = __('api.docs.title');
            $document->info->description = __('api.docs.description');
            (new MobileOpenApiDocument)($document);
        });

        /*
         * Chi vede `/docs/api` fuori dallo sviluppo (D35).
         *
         * Il pacchetto lascia passare chiunque in ambiente `local` e in ogni
         * altro ambiente interroga questo cancello, che senza una definizione
         * nega a tutti: oggi la documentazione è quindi invisibile in
         * produzione, il che è sicuro ma inutile.
         *
         * Si apre allo **staff editoriale autenticato** — gli stessi ruoli che
         * entrano in `/admin` — e non a una chiave condivisa: una chiave è un
         * segreto che non scade, che finisce in una chat e che non dice mai
         * *chi* l'ha usata, mentre un ruolo si toglie a una persona sola e ha
         * effetto al primo caricamento successivo. La documentazione descrive
         * anche gli endpoint di scrittura e la forma degli errori: non è un
         * segreto, ma non è nemmeno un invito a inventariare la superficie.
         *
         * `?User` e non `User`: senza il punto interrogativo Laravel non
         * chiamerebbe nemmeno la richiusa per chi non è collegato, e la
         * risposta sarebbe la stessa — ma per un motivo che nessuno legge nel
         * codice.
         */
        Gate::define('viewApiDocs', static fn (?User $user): bool => $user?->isEditorialStaff() === true);

        Gate::policy(Venue::class, VenuePolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Sponsorship::class, SponsorshipPolicy::class);
        Gate::policy(EventOccurrence::class, EventOccurrencePolicy::class);
        Gate::policy(TicketTier::class, TicketTierPolicy::class);
        Gate::policy(VenueApplication::class, VenueApplicationPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(City::class, CityPolicy::class);
        Gate::policy(ImportSource::class, ImportSourcePolicy::class);
        Gate::policy(SavedEvent::class, SavedEventPolicy::class);
        Gate::policy(ScheduledNotification::class, ScheduledNotificationPolicy::class);
        Gate::policy(Follow::class, FollowPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Redirect::class, RedirectPolicy::class);
    }
}
