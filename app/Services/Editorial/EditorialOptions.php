<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Actions\Users\SyncUserRoles;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\CarpoolCaseStatus;
use App\Enums\CatalogReviewStatus;
use App\Enums\EventCommentStatus;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\NotificationStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\RideStatus;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\User;
use BackedEnum;

/**
 * Gli elenchi chiusi e i tetti che l'app della redazione altrimenti
 * scriverebbe a mano: `GET /api/v1/editorial/options`.
 *
 * Come `ManagementOptions`: ogni valore viene dall'enum o dalla costante che
 * il sito usa davvero, con le etichette italiane del sito, nell'ordine
 * dell'enum. I pacchetti aggiungono qui gli elenchi che servono alle loro
 * schermate, una chiave per elenco.
 */
final class EditorialOptions
{
    /** Il motivo di un rifiuto, di una sospensione, di una nota: gli stessi tetti dei moduli del pannello. */
    public const REASON_MIN = 5;

    public const REASON_MAX = 1000;

    /** @return array<string, mixed> */
    public function payload(User $user): array
    {
        return [
            'event_statuses' => self::cases(EventStatus::cases()),
            'event_sources' => self::cases(EventSource::cases()),
            'submission_statuses' => self::cases(SubmissionStatus::cases()),
            'venue_statuses' => self::cases(VenueStatus::cases()),
            'venue_types' => self::cases(VenueType::cases()),
            'application_statuses' => self::cases(ApplicationStatus::cases()),
            'application_kinds' => self::cases(ApplicationKind::cases()),
            'report_reasons' => self::cases(ReportReason::cases()),
            'report_statuses' => self::cases(ReportStatus::cases()),
            'comment_statuses' => self::cases(EventCommentStatus::cases()),
            // Il pannello le etichetta con `reviews.<stato>` (l'enum non ha `label()`).
            'review_statuses' => array_map(static fn (CatalogReviewStatus $status): array => ['value' => $status->value, 'label' => (string) __('reviews.'.$status->value)], CatalogReviewStatus::cases()),
            'carpool_case_statuses' => self::cases(CarpoolCaseStatus::cases()),
            // R7: il filtro «Stato» della sezione «Passaggi» della pagina dei passaggi.
            'ride_statuses' => self::cases(RideStatus::cases()),
            'notification_statuses' => self::cases(NotificationStatus::cases()),
            'sponsorship_placements' => self::cases(SponsorshipPlacement::cases()),
            'sponsorship_pacings' => self::cases(SponsorshipPacing::cases()),
            'sponsorship_statuses' => self::cases(SponsorshipStatus::cases()),
            'sponsorship_order_statuses' => self::cases(SponsorshipOrderStatus::cases()),
            'roles' => $this->roles($user),
            'limits' => [
                'per_page_default' => config()->integer('api.limits.default'),
                'per_page_max' => config()->integer('api.limits.max'),
                'reason_min' => self::REASON_MIN,
                'reason_max' => self::REASON_MAX,
                'unlock_hours' => EditorialUnlocks::UNLOCK_HOURS,
                'password_minutes' => EditorialUnlocks::PASSWORD_MINUTES,
                'lock_after_minutes' => EditorialUnlocks::LOCK_AFTER_MINUTES,
            ],
        ];
    }

    /**
     * I ruoli di piattaforma, con chi li può conferire.
     *
     * - `assignable`: la regola del pannello (`SyncUserRoles::assignable()`)
     *   (solo un super admin conferisce `super_admin`; gli altri fino ad
     *   `admin`), per chi chiede.
     * - `assignable_in_app`: in più la decisione 4 della redazione: dall'app
     *   si conferisce al massimo `moderator`; `admin` e `super_admin` solo dal
     *   sito.
     *
     * @return list<array{value: string, label: string, assignable: bool, assignable_in_app: bool}>
     */
    public function roles(User $user): array
    {
        $assignable = SyncUserRoles::assignable($user);
        $inApp = SyncUserRoles::assignable($user, inApp: true);

        return array_map(static fn (UserRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'assignable' => in_array($role->value, $assignable, true),
            'assignable_in_app' => in_array($role->value, $inApp, true),
        ], UserRole::cases());
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function cases(array $cases): array
    {
        return array_values(array_map(static fn (BackedEnum $case): array => [
            'value' => (string) $case->value,
            'label' => method_exists($case, 'label') ? (string) $case->label() : (string) $case->value,
        ], $cases));
    }
}
