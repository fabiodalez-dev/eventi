<?php

declare(strict_types=1);

namespace App\Support\Editorial;

use App\Enums\ApplicationStatus;
use App\Enums\CatalogReviewStatus;
use App\Enums\EventCommentStatus;
use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Users\RegistrationState;
use App\Models\CarpoolCase;
use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventSubmission;
use App\Models\Report;
use App\Models\ScheduledNotification;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Queries\EditorialDashboardQuery;
use App\Services\Carpool\CommunitySafety;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * I contatori del menu della redazione (R-DB-13) per l'app.
 *
 * Sono le stesse query di `getNavigationBadge()` delle risorse del pannello,
 * con le stesse chiavi di cache (`nav-badge:*`, 60 secondi): pannello e app
 * leggono lo stesso numero, e uno dei due scalda la cache per l'altro. Dove
 * il pannello non mette in cache (iscrizioni, ordini), non la metto
 * nemmeno qui. `EditorialBadgesTest` confronta ogni numero con quello della
 * risorsa, così le due copie non possono divergere senza che un test lo dica.
 *
 * Un contatore vale `null` quando la persona non vede la voce del menu
 * (le recensioni e gli ordini al moderatore), come nel pannello.
 *
 * Sta in `App\Support` e non in `App\Services` perché le iscrizioni in
 * sospeso le definisce `RegistrationState`, che vive ancora accanto alla
 * risorsa degli utenti (la sposta R5).
 */
final class EditorialBadges
{
    /** Le chiavi, nell'ordine del menu del pannello. */
    public const KEYS = [
        'events_pending', 'event_submissions_pending', 'venues_pending', 'venue_applications_pending',
        'users_registrations', 'reports_open', 'event_comments_hidden', 'catalog_reviews_pending',
        'carpool_open', 'sponsorship_orders_awaiting', 'scheduled_notifications_due',
    ];

    public function __construct(private readonly CommunitySafety $safety) {}

    /** @return array<string, int|null> */
    public function for(User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            // `EventResource::getNavigationBadge`.
            'events_pending' => (int) Cache::remember('nav-badge:events-pending', 60, fn (): int => Event::query()->where('status', EventStatus::Pending)->count()),
            // `EventSubmissionResource::getNavigationBadge`.
            'event_submissions_pending' => $gate->allows('viewAny', EventSubmission::class)
                ? (int) Cache::remember('nav-badge:event-submissions-pending', 60, fn (): int => EventSubmission::query()->pending()->count()) : null,
            // `VenueResource::getNavigationBadge`.
            'venues_pending' => (int) Cache::remember('nav-badge:venues-pending', 60, fn (): int => Venue::query()->where('status', VenueStatus::Pending)->count()),
            // `VenueApplicationResource::getNavigationBadge`.
            'venue_applications_pending' => (int) Cache::remember('nav-badge:venue-applications-pending', 60, fn (): int => VenueApplication::query()->where('status', ApplicationStatus::Pending)->count()),
            // `UserResource::getNavigationBadge`: senza cache, come nel pannello.
            'users_registrations' => $gate->allows('viewAny', User::class)
                ? RegistrationState::scopeRecent(RegistrationState::scopeIncomplete(User::query()))->count() : null,
            // `ReportResource::getNavigationBadge`.
            'reports_open' => (int) Cache::remember('nav-badge:reports-open', 60, fn (): int => Report::query()->tap(EditorialDashboardQuery::openReportScope(...))->count()),
            // `EventCommentResource::getNavigationBadge`.
            'event_comments_hidden' => (int) Cache::remember('nav-badge:event-comments-hidden', 60, fn (): int => EventComment::query()->where('status', EventCommentStatus::Hidden)->count()),
            // `CatalogReviewResource::getNavigationBadge`: la voce c'è solo per admin e super admin.
            'catalog_reviews_pending' => $gate->allows('viewAny', CatalogReview::class)
                ? (int) Cache::remember('nav-badge:catalog-reviews-pending', 60, fn (): int => CatalogReview::query()->where('status', CatalogReviewStatus::Pending)->count()) : null,
            // `Carpool::getNavigationBadge`.
            'carpool_open' => $this->safety->staff($user, Permission::ManageCommunityCases)
                ? (int) Cache::remember('nav-badge:carpool-open', 60, fn (): int => CarpoolCase::whereNull('closed_at')->count()) : null,
            // `SponsorshipOrderResource::getNavigationBadge`: senza cache, solo admin e super admin.
            'sponsorship_orders_awaiting' => $gate->allows('viewAny', SponsorshipOrder::class)
                ? SponsorshipOrder::query()->whereIn('status', SponsorshipOrderStatus::awaitingDecision())->count() : null,
            // `ScheduledNotificationResource::getNavigationBadge`.
            'scheduled_notifications_due' => $gate->allows('viewAny', ScheduledNotification::class)
                ? (int) Cache::remember('nav-badge:scheduled-notifications-due', 60, fn (): int => ScheduledNotification::query()->due(CarbonImmutable::now())->count()) : null,
        ];
    }
}
