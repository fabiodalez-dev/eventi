<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\EventStatus;
use App\Enums\NotificationStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\CatalogReview;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventSubmission;
use App\Models\ImportSource;
use App\Models\Page;
use App\Models\Report;
use App\Models\ScheduledNotification;
use App\Models\SearchMiss;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Services\Carpool\CommunitySafety;
use Illuminate\Support\Facades\Gate;

/**
 * Che cosa può fare una persona della redazione, area per area, per l'app.
 *
 * Nessuna regola nuova: ogni chiave rifà la domanda che il pannello `/admin`
 * fa per la stessa pagina o azione, alla stessa policy. Dove il pannello non
 * chiede a una policy ma al ruolo (`canAccess()` delle pagine scritte a mano)
 * la chiave rifà quella domanda, con il nome della pagina nel commento:
 * questo servizio sta sotto `App\Services` e non può chiamare le classi di
 * `App\Filament` (deptrac).
 *
 * Le policy che vogliono un record si interrogano con un record di prova,
 * mai salvato: per lo staff globale rispondono dal ruolo e dal permesso, non
 * dal record. L'app usa le chiavi per mostrare o nascondere le voci; il
 * server ricontrolla ogni azione.
 */
final class EditorialPermissions
{
    /** Le chiavi, nell'ordine in cui le documenta il contratto. */
    public const KEYS = [
        'events_view', 'events_edit', 'events_publish', 'events_moderate', 'events_feature', 'submissions_publish',
        'venues_view', 'venues_edit', 'venues_moderate', 'venues_members', 'applications_review',
        'users_view', 'users_manage', 'users_roles',
        'reports_resolve', 'reviews_moderate', 'comments_moderate', 'comments_delete',
        'community_manage', 'cases_manage', 'carpool_manage', 'messages_read',
        'sponsorships_manage', 'social_use', 'analytics_view', 'search_misses_manage',
        'catalog_manage', 'pages_manage', 'imports_manage', 'notifications_view', 'notifications_cancel',
        'system_view', 'system_manage', 'mail_manage',
    ];

    public function __construct(private readonly CommunitySafety $safety) {}

    /** @return array<string, bool> */
    public function for(User $user): array
    {
        $gate = Gate::forUser($user);
        $admin = $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
        $event = $this->sampleEvent();
        $venue = $this->sampleVenue();

        $permissions = [
            // `EventPolicy::view` su un evento non pubblicato: l'elenco e la scheda in sola lettura.
            'events_view' => $gate->allows('view', $event),
            // `EventPolicy::update`: «Modifica», «Nuovo evento», date e locandina.
            'events_edit' => $gate->allows('update', $event),
            // `EventPolicy::publish`: «Pubblica», «Togli dalla pubblicazione» (anche al moderatore, come il pannello).
            'events_publish' => $gate->allows('publish', $event),
            // `EventPolicy::moderate`: rifiuto e annullamento.
            'events_moderate' => $gate->allows('moderate', $event),
            // `EventPolicy::feature`: «In evidenza».
            'events_feature' => $gate->allows('feature', Event::class),
            // `EditEventSubmission`: «Crea e pubblica» chiede `events.publish` e `EventPolicy::create`.
            'submissions_publish' => $gate->allows('update', new EventSubmission)
                && $user->can(Permission::PublishEvents->value) && $gate->allows('create', Event::class),
            // `venues.view`: l'elenco dei locali e la scheda in sola lettura.
            'venues_view' => $user->can(Permission::ViewVenues->value),
            // `VenuePolicy::update`: modifica, immagini, ticketing.
            'venues_edit' => $gate->allows('update', $venue),
            // `VenuePolicy::moderate`: approva, rifiuta, sospendi, verifica.
            'venues_moderate' => $gate->allows('moderate', $venue),
            // `VenuePolicy::manageCollaborators`: la scheda «Persone».
            'venues_members' => $gate->allows('manageCollaborators', $venue),
            // `VenueApplicationPolicy::update`: approva e rifiuta le richieste.
            'applications_review' => $gate->allows('update', new VenueApplication),
            // `UserPolicy::viewAny`.
            'users_view' => $gate->allows('viewAny', User::class),
            // `UserPolicy::create` (e `update` su chi ha rango più basso).
            'users_manage' => $gate->allows('create', User::class),
            // `RoleField` sta nel modulo dell'utente: lo vede chi lo modifica.
            'users_roles' => $user->can(Permission::ManageUsers->value),
            // `ReportPolicy::update`.
            'reports_resolve' => $gate->allows('update', new Report),
            // `CatalogReviewPolicy::update`.
            'reviews_moderate' => $gate->allows('update', new CatalogReview),
            // `EventCommentPolicy::hide`: lo staff globale nasconde e ripristina.
            'comments_moderate' => $gate->allows('hide', new EventComment),
            // `EventCommentPolicy::delete` su un commento d'altri: solo admin e super admin.
            'comments_delete' => $gate->allows('delete', new EventComment),
            // `Community::canAccess`.
            'community_manage' => $this->safety->staff($user, Permission::ManageCommunity),
            // `Carpool::canAccess`.
            'cases_manage' => $this->safety->staff($user, Permission::ManageCommunityCases),
            // Le sezioni e le azioni dei passaggi che chiedono `carpool.manage`.
            'carpool_manage' => $this->safety->staff($user, Permission::ManageCarpool),
            // I messaggi riservati: `staff(..., sensitive: true)`.
            'messages_read' => $this->safety->staff($user, Permission::ReadCommunityMessages, sensitive: true),
            // `SponsorshipPolicy::create`.
            'sponsorships_manage' => $gate->allows('create', Sponsorship::class),
            // `Social::canAccess` e `SocialSettings::canAccess`.
            'social_use' => $admin,
            // `EventShareAnalytics::canAccess`, `SeoOverview::canAccess`.
            'analytics_view' => $admin,
            // `SearchMissPolicy::viewAny` e `delete`: le ricerche senza risultati.
            'search_misses_manage' => $gate->allows('viewAny', SearchMiss::class),
            // `CategoryPolicy::create`: il catalogo si legge da tutti, si scrive con `categories.manage`.
            'catalog_manage' => $gate->allows('create', Category::class),
            // `PagePolicy::create`: pagine e redirect.
            'pages_manage' => $gate->allows('create', Page::class),
            // `ImportSourcePolicy::viewAny`.
            'imports_manage' => $gate->allows('viewAny', ImportSource::class),
            // `ScheduledNotificationPolicy::viewAny`.
            'notifications_view' => $gate->allows('viewAny', ScheduledNotification::class),
            // `ScheduledNotificationPolicy::cancel` su un invio in attesa.
            'notifications_cancel' => $gate->allows('cancel', $this->pendingNotification()),
            // `ScheduledOperations::canAccess`.
            'system_view' => $admin,
            // `CachePerformance::canAccess`, `NotificationTexts::canAccess`.
            'system_manage' => $admin,
            // `MailConfiguration::canAccess` e `Newsletter::canAccess`: solo il super admin.
            'mail_manage' => $user->hasRole(UserRole::SuperAdmin->value),
        ];

        return array_replace(array_fill_keys(self::KEYS, false), $permissions);
    }

    /** Gate di comodo per i controller dei pacchetti: la stessa risposta di `for()`, per una chiave. */
    public function allows(User $user, string $key): bool
    {
        return ($this->for($user)[$key] ?? false) === true;
    }

    /** Un evento in attesa, senza locale, mai salvato: serve solo a chiedere alla policy. */
    private function sampleEvent(): Event
    {
        $event = new Event;
        $event->forceFill(['status' => EventStatus::Pending]);

        return $event;
    }

    /** Un locale di prova: le policy per lo staff globale non guardano l'id. */
    private function sampleVenue(): Venue
    {
        $venue = new Venue;
        $venue->forceFill(['id' => 0]);

        return $venue;
    }

    private function pendingNotification(): ScheduledNotification
    {
        $notification = new ScheduledNotification;
        $notification->forceFill(['status' => NotificationStatus::Pending]);

        return $notification;
    }
}
