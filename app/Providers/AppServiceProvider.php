<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\ImportSource;
use App\Models\Page;
use App\Models\Report;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Observers\EventObserver;
use App\Observers\EventOccurrenceObserver;
use App\Observers\TicketTierObserver;
use App\Observers\VenueObserver;
use App\Policies\CategoryPolicy;
use App\Policies\CityPolicy;
use App\Policies\EventOccurrencePolicy;
use App\Policies\EventPolicy;
use App\Policies\FollowPolicy;
use App\Policies\ImportSourcePolicy;
use App\Policies\PagePolicy;
use App\Policies\ReportPolicy;
use App\Policies\SavedEventPolicy;
use App\Policies\ScheduledNotificationPolicy;
use App\Policies\TagPolicy;
use App\Policies\TicketTierPolicy;
use App\Policies\UserPolicy;
use App\Policies\VenueApplicationPolicy;
use App\Policies\VenuePolicy;
use App\Services\Geo\GeoQueryInterface;
use App\Services\Geo\MariaDbGeoQuery;
use App\Services\Import\DnsHostResolver;
use App\Services\Import\HostResolver;
use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallLock;
use App\Services\Installer\RequirementsChecker;
use App\Support\Consent;
use App\Support\CurrentCity;
use App\Support\CurrentFollows;
use App\Support\CurrentSaves;
use App\Support\DateFormatter;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Le query geospaziali passano tutte da qui: cambiare motore di
        // database costa questa riga più una implementazione dell'interfaccia.
        $this->app->bind(GeoQueryInterface::class, MariaDbGeoQuery::class);

        // La risoluzione dei nomi che `ImportUrlGuard` interroga prima di
        // scaricare un calendario: dietro un'interfaccia perché la difesa
        // contro gli indirizzi interni si possa verificare senza DNS.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

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
         * Limiti dell'API (§13.4): 60 richieste al minuto per chi non è
         * autenticato, contate per indirizzo IP, 120 per chi lo è, contate per
         * utente. Il conteggio per utente e non per IP è ciò che evita che una
         * rete aziendale dietro un solo indirizzo si blocchi da sola.
         */
        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user('sanctum');

            return $user === null
                ? Limit::perMinute(config()->integer('api.rate_limit.anonymous'))->by($request->ip() ?? 'sconosciuto')
                : Limit::perMinute(config()->integer('api.rate_limit.authenticated'))->by('utente:'.$user->getAuthIdentifier());
        });

        /*
         * Accesso, registrazione e reimpostazione password hanno un limite a
         * parte (§16). La chiave unisce indirizzo IP ed email: senza l'email,
         * chi prova mille password su un solo account resterebbe dentro il
         * limite di un IP condiviso; senza l'IP, basterebbe cambiare email a
         * ogni tentativo.
         */
        RateLimiter::for('api-auth', static function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(config()->integer('api.rate_limit.auth'))
                ->by(($request->ip() ?? 'sconosciuto').'|'.(is_string($email) ? mb_strtolower($email) : ''));
        });

        /*
         * Accesso, registrazione e collegamento di accesso del **sito** (§16).
         * Stesso ragionamento del limite dell'API: la chiave unisce indirizzo
         * IP ed email, perché senza l'email chi prova mille password su un
         * solo account resterebbe dentro il limite di un IP condiviso, e senza
         * l'IP basterebbe cambiare email a ogni tentativo.
         */
        RateLimiter::for('account-auth', static function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(config()->integer('api.rate_limit.auth'))
                ->by(($request->ip() ?? 'sconosciuto').'|'.(is_string($email) ? mb_strtolower($email) : ''));
        });

        // Le colonne morph (`follows.followable_type`, `reports.reportable_type`,
        // `scheduled_notifications.notifiable_type`) contengono alias brevi e non
        // nomi di classe: i dati non devono dipendere dal namespace PHP.
        Relation::enforceMorphMap([
            'city' => City::class,
            'venue' => Venue::class,
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

        // Quando un evento ha delle fasce di prezzo, sono loro a dettare
        // `price_min`/`price_max`: il filtro «fino a 10 €» è una WHERE su
        // quelle colonne, e un minimo calcolato al render ne resterebbe fuori.
        TicketTier::observe(TicketTierObserver::class);

        // `venues.location` non si scrive a mano: è il punto geometrico
        // derivato da `lat`/`lng`, ed è ciò su cui gira l'indice spaziale.
        Venue::observe(VenueObserver::class);

        /*
         * La documentazione OpenAPI (`/docs/api`) prende titolo e descrizione
         * da `lang/it`, non dalla configurazione: sono testi, e i testi stanno
         * in un posto solo (§2 delle convenzioni).
         */
        Scramble::configure()->withDocumentTransformers(static function (OpenApi $document): void {
            $document->info->title = __('api.docs.title');
            $document->info->description = __('api.docs.description');
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
    }
}
