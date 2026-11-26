<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\DTOs\ExternalLinkList;
use App\DTOs\FactList;
use App\Enums\AttendanceMode;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\SeoIndexing;
use App\Enums\TicketTierStatus;
use App\Enums\VerificationStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\Booking;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventFeature;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Models\User;
use App\Services\Events\EditorialEventEditor;
use App\Services\Events\VenueEventEditor;
use App\Services\Management\ManagementOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Quello che l'app riceve per disegnare il modulo evento della redazione:
 * le scelte dei campi (`options`) e, in modifica, i valori della scheda
 * (`event`) con le azioni permesse.
 *
 * I valori hanno la forma della scheda della gestione (`ManagedEventDetail`
 * nell'app) per i campi che i due moduli hanno in comune — così l'app riusa
 * lo stesso modulo — più i campi che il pannello mostra solo alla redazione.
 */
final class EditorialEventForm
{
    public function __construct(
        private readonly EditorialEventEditor $editor,
        private readonly ManagementOptions $catalog,
    ) {}

    /**
     * Le scelte del modulo: elenchi chiusi con le etichette del pannello,
     * città, categorie, valori predefiniti di un evento nuovo, tetti dei
     * ripetitori e il catalogo di «Prima di andare» (`GET /management/options`).
     *
     * @return array<string, mixed>
     */
    public function options(User $actor): array
    {
        $choice = fn (array $options): array => collect($options)->map(fn (string $label, string|int $value): array => ['value' => (string) $value, 'label' => $label])->values()->all();
        $city = EditorialEventEditor::defaultCity();

        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category): array => ['id' => (int) $category->getKey(), 'name' => (string) $category->name])->values()->all(),
            'cities' => City::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'timezone'])
                ->map(fn (City $item): array => ['id' => (int) $item->getKey(), 'name' => (string) $item->name, 'timezone' => (string) $item->timezone])->values()->all(),
            'statuses' => $choice(EventStatus::options()),
            'sources' => $choice(EventSource::options()),
            'verification_statuses' => $choice(VerificationStatus::options()),
            'price_types' => $choice(PriceType::options()),
            'ticket_tier_statuses' => $choice(TicketTierStatus::options()),
            'occurrence_statuses' => $choice(OccurrenceStatus::options()),
            'lineup_roles' => $choice(LineupRole::options()),
            'scopes' => collect(OccurrenceScope::cases())->map(fn (OccurrenceScope $scope): array => ['value' => $scope->value, 'label' => $scope->label(), 'help' => $scope->helpText()])->values()->all(),
            'attendance_modes' => $choice([
                AttendanceMode::Offline->value => __('seo.offline'),
                AttendanceMode::Online->value => __('seo.online'),
                AttendanceMode::Mixed->value => __('seo.mixed'),
            ]),
            'parking_types' => $choice(['free' => __('seo.parking_free'), 'paid' => __('seo.parking_paid'), 'none' => __('seo.parking_none')]),
            'organizer_types' => $choice(['Organization' => __('seo.organization'), 'Person' => __('seo.person')]),
            'seo_indexing' => $choice([SeoIndexing::Automatic->value => __('seo.automatic'), SeoIndexing::Excluded->value => __('seo.excluded')]),
            'defaults' => [
                'status' => EventStatus::Draft->value,
                'city_id' => $city === null ? null : (int) $city->getKey(),
                'price_type' => PriceType::Unknown->value,
                'currency' => 'EUR',
                'source' => EventSource::Manual->value,
                'verification_status' => VerificationStatus::Unverified->value,
                'editorial_score' => 0,
            ],
            'limits' => [
                'ticket_tiers' => VenueEventEditor::MAX_TIERS,
                'tags' => 50,
                'agenda' => 50,
                'faqs' => 30,
                'facts' => FactList::MAX_FACTS,
                'external_links' => ExternalLinkList::MAX_LINKS,
                'new_dates' => EditorialEventEditor::MAX_NEW_DATES,
                'short_description' => 500,
            ],
            'can' => [
                'feature' => Gate::forUser($actor)->allows('feature', Event::class),
                'admin' => $actor->hasAnyRole(['admin', 'super_admin']),
                // «Crea caratteristica» accanto a «Caratteristiche e servizi», come `createOptionAction` del pannello.
                'create_feature' => Gate::forUser($actor)->allows('create', EventFeature::class),
            ],
            'catalog' => $this->catalog->payload(),
        ];
    }

    /**
     * I valori della scheda per riaprirla in modifica, con le date (per il
     * programma e per la biglietteria), le ricorrenze, locandina e galleria,
     * e le azioni della testata della pagina «Modifica» del pannello.
     *
     * @return array<string, mixed>
     */
    public function values(Event $event, User $actor): array
    {
        $event->loadMissing(['city', 'venue', 'category', 'organizer', 'organizers', 'tags', 'media', 'recurrences']);
        $timezone = $event->city->timezone;
        $tiers = $event->ticketTiers()->whereNull('occurrence_id')->get();
        $dates = $event->occurrences()->orderBy('starts_at')->get()->each(fn (EventOccurrence $date) => $date->setRelation('event', $event));
        $gate = Gate::forUser($actor);
        $update = $gate->allows('update', $event);
        $seo = is_array($event->getAttribute('seo')) ? $event->getAttribute('seo') : [];
        $place = is_array($event->custom_location) ? $event->custom_location : null;
        $ref = fn (?object $model): ?array => $model === null ? null : ['id' => (int) $model->getKey(), 'name' => (string) $model->getAttribute('name')];

        return [
            'id' => (int) $event->getKey(),
            'slug' => (string) $event->slug,
            'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
            'title' => (string) $event->title,
            'subtitle' => $event->subtitle,
            'short_description' => $event->short_description,
            'description' => $event->description,
            'category' => $ref($event->category),
            'tags' => $event->tags->map(fn (Tag $tag): array => ['id' => (int) $tag->getKey(), 'name' => (string) $tag->name])->values()->all(),
            'age_restriction' => $event->age_restriction,
            'language' => $event->language,
            'video_url' => $event->video_url,
            'city' => ['id' => (int) $event->city->getKey(), 'name' => (string) $event->city->name, 'timezone' => $timezone],
            'venue' => $ref($event->venue),
            'custom_location' => $place === null ? null : [
                'name' => $place['name'] ?? null,
                'address' => $place['address'] ?? null,
                'lat' => is_numeric($place['lat'] ?? null) ? (float) $place['lat'] : null,
                'lng' => is_numeric($place['lng'] ?? null) ? (float) $place['lng'] : null,
            ],
            'is_outdoor' => (bool) $event->is_outdoor,
            'organizer' => $ref($event->organizer),
            'organizers' => $event->organizers->map(fn (Organizer $organizer): array => ['id' => (int) $organizer->getKey(), 'name' => (string) $organizer->name])->values()->all(),
            'organizer_name' => $event->organizer_name,
            'organizer_url' => $event->organizer_url,
            'price_type' => ['value' => $event->price_type->value, 'label' => $event->price_type->label()],
            'price_min' => $event->price_min === null ? null : (float) $event->price_min,
            'price_max' => $event->price_max === null ? null : (float) $event->price_max,
            'price_notes' => $event->price_notes,
            'currency' => $event->currency ?: 'EUR',
            'ticket_url' => $event->ticket_url,
            'booking_required' => (bool) $event->booking_required,
            'booking_url' => $event->booking_url,
            'booking_phone' => $event->booking_phone,
            'ticket_tiers' => $tiers->map(fn (TicketTier $tier): array => [
                'id' => (int) $tier->getKey(),
                'name' => (string) $tier->name,
                'price' => $tier->price === null ? null : (float) $tier->price,
                'status' => $tier->status->value,
                'url' => $tier->url,
                'note' => $tier->note,
            ])->values()->all(),
            'content_details' => self::object($this->editor->details($event)),
            'facts' => FactList::fromMixed($event->getRawOriginal('facts'))->toArray(),
            'external_links' => ExternalLinkList::fromMixed($event->getRawOriginal('external_links'))->toArray(),
            'seo' => [
                'title' => $seo['title'] ?? null,
                'description' => $seo['description'] ?? null,
                'image' => $seo['image'] ?? null,
                'indexing' => $seo['indexing'] ?? SeoIndexing::Automatic->value,
            ],
            'is_demo' => (bool) $event->getAttribute('is_demo'),
            'source' => ['value' => $event->source->value, 'label' => $event->source->label()],
            'verification_status' => $event->verification_status->value,
            'editorial_score' => (int) $event->editorial_score,
            'is_featured' => (bool) $event->is_featured,
            'featured_until' => self::iso($event->featured_until),
            'rejection_reason' => $event->rejection_reason,
            'scheduled_publish_at' => self::iso($event->scheduled_publish_at),
            'published_at' => self::iso($event->published_at),
            'recurrences' => $event->recurrences->sortBy('id')->map(fn (EventRecurrence $recurrence): array => [
                'id' => (int) $recurrence->getKey(),
                'rrule' => (string) $recurrence->rrule,
                'until' => self::iso($recurrence->until),
                'exdates' => array_values(array_map('strval', is_array($recurrence->exdates) ? $recurrence->exdates : [])),
                'generated_until' => self::iso($recurrence->generated_until),
            ])->values()->all(),
            'poster' => PosterResource::toArray($event),
            'gallery' => $event->getMedia('gallery')->map(fn (Media $media): array => ['id' => (int) $media->getKey(), ...PosterResource::media($media)])->values()->all(),
            // Le date per il programma («Data collegata») e i collegamenti «Gestisci biglietti» della scheda Biglietti.
            'date_refs' => $dates->map(fn (EventOccurrence $date): array => [
                'id' => (int) $date->getKey(),
                'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
                'ticketing_path' => $gate->allows('manage', [Booking::class, $date]) ? 'gestione-biglietti/'.$date->getKey() : null,
            ])->values()->all(),
            'ticketing_enabled' => (bool) $event->venue?->ticketing_enabled,
            'abilities' => [
                'update' => $update,
                'feature' => $gate->allows('feature', Event::class),
                'admin' => $actor->hasAnyRole(['admin', 'super_admin']),
                // «Programma pubblicazione» e «Annulla programmazione» (`PublicationActions`).
                'schedule' => $update && in_array($event->status, [EventStatus::Draft, EventStatus::Pending], true),
                'unschedule' => $update && $event->scheduled_publish_at !== null,
                // «Genera date» (`EditEvent::generateOccurrencesAction`): visibile solo con una ricorrenza.
                'generate' => $update && $event->recurrences->isNotEmpty(),
                'dates' => $gate->allows('view', $event),
            ],
        ];
    }

    /**
     * Un oggetto JSON anche quando è vuoto.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function object(mixed $value): array|\stdClass
    {
        return is_array($value) && $value !== [] ? $value : new \stdClass;
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601String() : null;
    }
}
