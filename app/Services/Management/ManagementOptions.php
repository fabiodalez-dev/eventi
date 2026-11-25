<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Actions\PublishVenueNotice;
use App\DTOs\ExternalLinkList;
use App\DTOs\FactList;
use App\Enums\AccessibilityFeature;
use App\Enums\AgeGroup;
use App\Enums\BookingQuestionType;
use App\Enums\LineupRole;
use App\Enums\MembershipRequirement;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\SocialFormat;
use App\Enums\SponsorshipPlacement;
use App\Enums\TransitMode;
use App\Models\EventFeature;
use App\Rules\RealImage;
use App\Support\PracticalIcons;
use App\Support\VenueClosures;

/**
 * Gli elenchi chiusi e i tetti che l'app di gestione altrimenti scriverebbe a
 * mano: `GET /api/v1/management/options`.
 *
 * Ogni valore viene dal punto che il sito usa davvero (enum, costanti dei
 * DTO, `config/`), così un tetto cambiato sul server arriva all'app senza una
 * versione nuova. Le etichette sono quelle italiane del sito.
 */
final class ManagementOptions
{
    /** I file della galleria di un locale: lo stesso tetto del profilo nel pannello. */
    public const GALLERY_MAX = 30;

    /**
     * Gli stati che il pannello del locale lascia mettere a una data: le azioni
     * «Annulla data» e «Tutto esaurito» (e il ritorno «in programma»). Il
     * pannello dell'organizzazione offre invece l'elenco completo.
     */
    public const VENUE_OCCURRENCE_STATUSES = [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut, OccurrenceStatus::Cancelled];

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'price_types' => self::cases(PriceType::cases()),
            'occurrence_statuses' => [
                'venue' => self::cases(self::VENUE_OCCURRENCE_STATUSES),
                'organizer' => self::cases(OccurrenceStatus::cases()),
            ],
            'recurrence_frequencies' => self::cases(RecurrenceFrequency::cases()),
            'lineup_roles' => self::cases(LineupRole::cases()),
            'accessibility_features' => self::cases(AccessibilityFeature::cases()),
            'transit_modes' => self::cases(TransitMode::cases()),
            'age_groups' => self::cases(AgeGroup::cases()),
            'membership_requirements' => self::cases(MembershipRequirement::cases()),
            'practical_icons' => self::pairs(PracticalIcons::options()),
            'event_features' => EventFeature::query()->where('is_active', true)->where('is_system', false)
                ->orderBy('group')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'group', 'icon'])
                ->map(fn (EventFeature $feature): array => [
                    'id' => (int) $feature->getKey(),
                    'name' => (string) $feature->name,
                    'group' => $feature->group,
                    'icon' => $feature->icon,
                ])->values()->all(),
            'booking_question_types' => array_map(fn (BookingQuestionType $type): array => [
                'value' => $type->value,
                'label' => (string) __('ticketing.questions.types.'.$type->value),
            ], BookingQuestionType::cases()),
            'social_formats' => array_map(fn (SocialFormat $format): array => [
                'value' => $format->value,
                'label' => (string) __('social.'.$format->value),
                'width' => 1080,
                'height' => $format->height(),
            ], SocialFormat::cases()),
            'sponsorship_placements' => array_map(fn (SponsorshipPlacement $placement): array => [
                'value' => $placement->value,
                'label' => $placement->label(),
                'sells_views' => $placement->sellsViews(),
            ], SponsorshipPlacement::cases()),
            'limits' => [
                'external_links' => ExternalLinkList::MAX_LINKS,
                'facts' => FactList::MAX_FACTS,
                'venue_closures' => VenueClosures::MAX,
                'venue_notice_min_days_between' => PublishVenueNotice::MIN_DAYS_BETWEEN,
                'attendee_messages_per_day' => config()->integer('ticketing.messages.daily_limit'),
                // Questi cinque sono scritti nelle regole dei moduli (`MessageAttendeesRequest`,
                // `ManageRequest`, `BeforeGoingFields`): qui ne riporto il valore.
                'attendee_message_subject_max' => 160,
                'attendee_message_body_max' => 2000,
                'booking_questions' => 10,
                'practical_custom_venue' => 12,
                'practical_custom_event' => 24,
                'gallery_images' => self::GALLERY_MAX,
            ],
            'images' => [
                'max_upload_bytes' => config()->integer('media.max_upload_bytes'),
                'accepted_mime_types' => RealImage::acceptedMimeTypes(),
                'accepted_extensions' => RealImage::acceptedExtensions(),
                'min_width' => config()->integer('media.min_width'),
                'min_height' => config()->integer('media.min_height'),
                'max_pixels' => config()->integer('media.max_pixels'),
                'max_original_width' => config()->integer('media.max_original_width'),
            ],
        ];
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function cases(array $cases): array
    {
        return array_map(fn ($case): array => [
            'value' => (string) $case->value,
            'label' => method_exists($case, 'label') ? (string) $case->label() : (string) $case->value,
        ], $cases);
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $options): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($options), $options);
    }
}
