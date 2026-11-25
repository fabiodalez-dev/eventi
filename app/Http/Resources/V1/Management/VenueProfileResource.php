<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Management;

use App\Actions\PublishVenueNotice;
use App\DTOs\Fact;
use App\DTOs\FactList;
use App\DTOs\TransitGuide;
use App\DTOs\TransitLine;
use App\Enums\VenueNoticeKind;
use App\Filament\Admin\Support\StructuredFields;
use App\Models\Venue;
use App\Models\VenueNotice;
use App\Support\Api\ApiDate;
use App\Support\VenueClosures;
use App\Support\VenueGeography;
use App\Support\WidgetEmbed;
use Carbon\CarbonImmutable;

/**
 * La scheda del locale come la compila la pagina «Il tuo locale», per l'app
 * di gestione: gli stessi campi, nella forma del modulo (orari in righe,
 * social come coppie nome + indirizzo), più quello che serve a disegnare le
 * sezioni (comuni e quartieri fra cui scegliere, tetti, stato del modulo di
 * contatto e dell'ultimo avviso) e i tre strumenti da distribuire: la pagina
 * pubblica, il calendario da sottoscrivere e il riquadro incorporabile.
 *
 * Gli elenchi comuni a più aree (icone e caratteristiche di «Prima di
 * andare», voci di accessibilità, mezzi, fasce d'età) stanno in
 * `GET /management/options`.
 */
final class VenueProfileResource
{
    /** Le chiavi di `content_details` che la pagina del locale mostra. */
    public const CONTENT_KEYS = [
        // Prima di andare
        'age_groups', 'stroller', 'changing_table', 'kids_area', 'membership', 'accessibility',
        'membership_notes', 'accessibility_notes', 'feature_ids', 'practical_custom', 'food_notes', 'start_notes',
        // Informazioni editoriali
        'introduction', 'parking_type', 'parking_notes', 'transit_notes', 'entrance_notes', 'faqs',
    ];

    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Le righe degli orari: lo stesso tetto del ripetitore del pannello. */
    public const OPENING_HOURS_MAX = 28;

    public const SOCIALS_MAX = 12;

    /** @return array<string, mixed> */
    public static function toArray(Venue $venue): array
    {
        $timezone = $venue->city->timezone ?? config()->string('app.timezone');
        // Colonne aggiunte da `HasEditorialContent`: lette per nome, possono essere vuote.
        $details = is_array($venue->getAttribute('content_details')) ? $venue->getAttribute('content_details') : [];
        $seo = is_array($venue->getAttribute('seo')) ? $venue->getAttribute('seo') : [];
        // Le coordinate mancano per i locali che nessuno ha ancora messo sulla mappa.
        $lat = $venue->getAttribute('lat');
        $lng = $venue->getAttribute('lng');

        return [
            'id' => (int) $venue->getKey(),
            'slug' => (string) $venue->slug,
            // Identità: si leggono e non si cambiano, come nel pannello.
            'name' => (string) $venue->name,
            'type' => ['value' => $venue->type->value, 'label' => $venue->type->label()],
            'status' => $venue->status->value,
            'city' => $venue->city === null ? null : ['name' => (string) $venue->city->name, 'timezone' => $timezone],

            'short_description' => $venue->short_description,
            'description' => $venue->description,

            'address' => $venue->address,
            'address_extra' => $venue->address_extra,
            'postal_code' => $venue->postal_code,
            'municipality' => $venue->municipality,
            'zone' => $venue->zone,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,

            'opening_hours' => StructuredFields::openingHoursToRows($venue->opening_hours),
            // Solo le chiusure in corso o in arrivo: le finite spariscono al salvataggio.
            'closures' => VenueClosures::upcoming(is_array($venue->closures) ? $venue->closures : null, $timezone),
            'capacity' => $venue->capacity,

            'phone' => $venue->phone,
            'email' => $venue->email,
            'website' => $venue->website,
            'socials' => self::socials($venue->socials),

            'transit' => $venue->transit->toArray(),
            // Solo le voci dichiarate: una voce assente è «non dichiarato», che non è «no».
            'accessibility' => (object) $venue->accessibility->toArray(),
            'info' => $venue->info->toArray(),
            'requires_membership' => (bool) $venue->requires_membership,
            'membership_notes' => $venue->membership_notes,

            'content_details' => (object) array_merge(array_fill_keys(self::CONTENT_KEYS, null), array_intersect_key($details, array_flip(self::CONTENT_KEYS))),
            'seo' => [
                'title' => $seo['title'] ?? null,
                'description' => $seo['description'] ?? null,
                'image' => $seo['image'] ?? null,
            ],

            'contact' => ContactSettingsResource::toArray($venue),
            'notices' => self::notices($venue, $timezone),

            'public_url' => route('venues.show', $venue->slug),
            'calendar_url' => route('venues.calendar', $venue->slug),
            'widget' => [
                'url' => WidgetEmbed::url($venue),
                'code' => WidgetEmbed::snippet($venue),
            ],

            'choices' => [
                'weekdays' => array_map(static fn (string $day, int $index): array => [
                    'value' => $day,
                    'label' => ucfirst(CarbonImmutable::now()->startOfWeek()->addDays($index)->locale(app()->getLocale())->isoFormat('dddd')),
                ], self::WEEKDAYS, array_keys(self::WEEKDAYS)),
                'municipalities' => VenueGeography::municipalities($venue->city),
                'districts' => VenueGeography::districts(),
                'district_municipality' => VenueGeography::DISTRICT_MUNICIPALITY,
                'parking_types' => [
                    ['value' => 'free', 'label' => (string) __('seo.parking_free')],
                    ['value' => 'paid', 'label' => (string) __('seo.parking_paid')],
                    ['value' => 'none', 'label' => (string) __('seo.parking_none')],
                ],
                'notice_kinds' => array_map(
                    static fn (VenueNoticeKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
                    VenueNoticeKind::cases(),
                ),
            ],

            'limits' => [
                'short_description' => 500,
                'opening_hours' => self::OPENING_HOURS_MAX,
                'closures' => VenueClosures::MAX,
                'closure_note' => 120,
                'capacity' => 1000000,
                'socials' => self::SOCIALS_MAX,
                'transit' => TransitGuide::MAX_LINES,
                'transit_text' => TransitLine::MAX_TEXT_LENGTH,
                'info' => FactList::MAX_FACTS,
                'info_label' => Fact::MAX_LABEL_LENGTH,
                'info_value' => Fact::MAX_VALUE_LENGTH,
                'practical_custom' => 12,
                'faqs' => 30,
                'notice_body' => 500,
                'seo_title' => 180,
                'seo_description' => 400,
            ],
        ];
    }

    /**
     * I social come coppie ordinate nome + indirizzo: nel database sono una
     * mappa `{"Instagram": "https://…"}`, che in JSON perderebbe l'ordine e
     * diventerebbe `[]` quando è vuota.
     *
     * @return list<array{name: string, url: string|null}>
     */
    public static function socials(mixed $socials): array
    {
        $rows = [];
        foreach (is_array($socials) ? $socials : [] as $name => $url) {
            $rows[] = ['name' => (string) $name, 'url' => is_scalar($url) ? (string) $url : null];
        }

        return $rows;
    }

    /**
     * L'ultimo avviso e da quando se ne può pubblicare un altro (uno ogni
     * `PublishVenueNotice::MIN_DAYS_BETWEEN` giorni), con quante persone lo
     * riceverebbero adesso.
     *
     * @return array<string, mixed>
     */
    private static function notices(Venue $venue, string $timezone): array
    {
        /** @var VenueNotice|null $latest */
        $latest = $venue->notices()->latest('created_at')->latest('id')->first();
        $next = $latest?->created_at?->copy()->addDays(PublishVenueNotice::MIN_DAYS_BETWEEN);

        return [
            'min_days_between' => PublishVenueNotice::MIN_DAYS_BETWEEN,
            'latest' => $latest === null ? null : [
                'kind' => $latest->kind->value,
                'kind_label' => $latest->kind->label(),
                'body' => (string) $latest->body,
                'until' => ApiDate::day($latest->until),
                'created_at' => ApiDate::instant($latest->created_at, $timezone),
            ],
            'next_available_at' => $next !== null && $next->isFuture() ? ApiDate::instant($next, $timezone) : null,
            'followers' => $venue->followers()->where('notify', true)->count(),
        ];
    }
}
