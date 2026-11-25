<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\AgeGroup;
use App\Enums\AttendanceMode;
use App\Enums\MembershipRequirement;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TicketTierStatus;
use App\Enums\VenueStatus;
use App\Enums\Weekday;
use App\Models\Event;
use App\Rules\ExternalLinks;
use App\Rules\FactRows;
use App\Support\PracticalIcons;
use Illuminate\Validation\Rule;

/**
 * Le regole dei campi del modulo evento, scritte una volta per i pannelli e
 * per l'API di gestione.
 *
 * Nei pannelli molte regole stanno sui componenti Filament (`->required()`,
 * `->maxLength()`); qui sono le stesse, in forma di regole di validazione,
 * perché l'app non passa da Livewire. Ogni metodo accetta il prefisso della
 * chiave: `data.` per il modulo Livewire, nessuno per l'API.
 */
final class EventFormRules
{
    /** Le chiavi di «Prima di andare» che un evento scrive (le stesse di `BeforeGoingFields`). */
    public const BEFORE_GOING_KEYS = [
        'membership', 'membership_notes', 'accessibility', 'accessibility_notes', 'feature_ids', 'practical_custom',
        'age_groups', 'stroller', 'changing_table', 'kids_area', 'food_notes', 'start_notes',
    ];

    /** Le informazioni editoriali di un evento (`EditorialFields::content(event: true)`). */
    public const EDITORIAL_KEYS = [
        'introduction', 'parking_type', 'parking_notes', 'transit_notes', 'entrance_notes', 'organizer_venue_id',
        'minimum_age', 'attendance_mode', 'online_url', 'organizer_type', 'mandatory_costs', 'weather_policy',
        'minors_policy', 'cancellation_policy', 'refund_policy', 'public_contact', 'poster_alt', 'poster_caption',
        'poster_credit', 'agenda', 'faqs',
    ];

    /** I testi editoriali brevi (2000 caratteri), nell'ordine del modulo. */
    private const EDITORIAL_TEXTS = [
        'parking_notes', 'transit_notes', 'entrance_notes', 'mandatory_costs', 'weather_policy', 'minors_policy',
        'cancellation_policy', 'refund_policy', 'public_contact', 'poster_alt', 'poster_caption', 'poster_credit',
    ];

    /**
     * «Prima di andare»: le stesse regole del salvataggio della bozza nel
     * modulo rapido del locale.
     *
     * @return array<string, list<mixed>>
     */
    public static function beforeGoing(string $prefix = '', bool $onlyBeforeGoing = true): array
    {
        $rules = [
            $prefix.'content_details' => $onlyBeforeGoing
                ? ['nullable', 'array:'.implode(',', self::BEFORE_GOING_KEYS)]
                : ['nullable', 'array:'.implode(',', [...self::BEFORE_GOING_KEYS, ...self::EDITORIAL_KEYS])],
            $prefix.'content_details.food_notes' => ['nullable', 'string', 'max:2000'],
            $prefix.'content_details.start_notes' => ['nullable', 'string', 'max:2000'],
            $prefix.'content_details.age_groups' => ['nullable', 'array', 'max:6'],
            $prefix.'content_details.age_groups.*' => [Rule::enum(AgeGroup::class)],
            $prefix.'content_details.stroller' => ['nullable', Rule::in(['yes', 'no'])],
            $prefix.'content_details.changing_table' => ['nullable', Rule::in(['yes', 'no'])],
            $prefix.'content_details.kids_area' => ['nullable', Rule::in(['yes', 'no'])],
            $prefix.'content_details.membership' => ['nullable', Rule::enum(MembershipRequirement::class)],
            $prefix.'content_details.accessibility' => ['nullable', Rule::in(['yes', 'no'])],
            $prefix.'content_details.membership_notes' => ['nullable', 'string', 'max:2000'],
            $prefix.'content_details.accessibility_notes' => ['nullable', 'string', 'max:2000'],
            $prefix.'content_details.feature_ids' => ['nullable', 'array'],
            $prefix.'content_details.feature_ids.*' => ['integer', Rule::exists('event_features', 'id')->where('is_active', true)->where('is_system', false)],
            $prefix.'content_details.practical_custom' => ['nullable', 'array', 'max:24'],
            $prefix.'content_details.practical_custom.*.label' => ['required', 'string', 'max:120'],
            $prefix.'content_details.practical_custom.*.icon' => ['required', Rule::in(array_keys(PracticalIcons::options()))],
            $prefix.'content_details.practical_custom.*.text' => ['nullable', 'string', 'max:1000'],
        ];

        return $rules;
    }

    /**
     * Le informazioni editoriali (introduzione, parcheggio, programma,
     * domande frequenti…). `$event` serve al programma, che può riferirsi a
     * una data dell'evento.
     *
     * @return array<string, list<mixed>>
     */
    public static function editorial(?Event $event, string $prefix = ''): array
    {
        $key = $prefix.'content_details.';
        $rules = [
            $key.'introduction' => ['nullable', 'string', 'max:5000'],
            $key.'parking_type' => ['nullable', Rule::in(['free', 'paid', 'none'])],
            $key.'organizer_venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)],
            $key.'minimum_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            $key.'attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            $key.'online_url' => ['nullable', 'string', 'url:http,https', 'max:2048', 'required_if:'.$key.'attendance_mode,online,mixed'],
            $key.'organizer_type' => ['nullable', Rule::in(['Organization', 'Person'])],
            $key.'agenda' => ['nullable', 'array', 'max:50'],
            $key.'agenda.*.title' => ['required', 'string', 'max:180'],
            $key.'agenda.*.when' => ['required', 'string', 'max:120'],
            $key.'agenda.*.starts_at' => ['nullable', 'date'],
            $key.'agenda.*.ends_at' => ['nullable', 'date', 'after:'.$key.'agenda.*.starts_at'],
            $key.'agenda.*.occurrence_id' => ['nullable', 'integer', Rule::exists('event_occurrences', 'id')->where('event_id', $event?->getKey() ?? 0)],
            $key.'agenda.*.speaker' => ['nullable', 'string', 'max:180'],
            $key.'agenda.*.description' => ['nullable', 'string', 'max:5000'],
            $key.'faqs' => ['nullable', 'array', 'max:30'],
            $key.'faqs.*.question' => ['required', 'string', 'max:250'],
            $key.'faqs.*.answer' => ['required', 'string', 'max:5000'],
        ];
        foreach (self::EDITORIAL_TEXTS as $text) {
            $rules[$key.$text] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /**
     * I campi della scheda comuni ai due soggetti: titolo, categoria, prezzo,
     * descrizione, tag. `$categoryRequired` dice se categoria e tipo di prezzo
     * sono obbligatori (la bozza del locale chiede solo il titolo).
     *
     * @return array<string, list<mixed>>
     */
    public static function basics(string $prefix = '', bool $categoryRequired = true, bool $descriptionRequired = false, int $ticketUrlMax = 255): array
    {
        return [
            $prefix.'title' => ['required', 'string', 'max:255'],
            $prefix.'description' => [$descriptionRequired ? 'required' : 'nullable', 'string', 'max:50000'],
            $prefix.'category_id' => [$categoryRequired ? 'required' : 'nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            $prefix.'price_type' => [$categoryRequired ? 'required' : 'nullable', Rule::enum(PriceType::class)],
            $prefix.'price_min' => ['nullable', 'numeric', 'between:0,999999.99'],
            $prefix.'price_max' => ['nullable', 'numeric', 'between:0,999999.99'],
            $prefix.'ticket_url' => ['nullable', 'string', 'url:http,https', 'max:'.$ticketUrlMax],
            $prefix.'tags' => ['nullable', 'array', 'max:50'],
            $prefix.'tags.*' => ['integer', 'distinct', Rule::exists('tags', 'id')],
        ];
    }

    /**
     * Il luogo diverso dalla sede (`custom_location`).
     *
     * @return array<string, list<mixed>>
     */
    public static function location(string $prefix = ''): array
    {
        return [
            $prefix.'custom_location' => ['nullable', 'array:name,address,lat,lng'],
            $prefix.'custom_location.name' => ['nullable', 'string', 'max:255'],
            $prefix.'custom_location.address' => ['nullable', 'string', 'max:255'],
            $prefix.'custom_location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            $prefix.'custom_location.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * I campi che ha solo il locale: link di prenotazione, link esterni.
     *
     * @return array<string, list<mixed>>
     */
    public static function venueLinks(string $prefix = ''): array
    {
        return [
            $prefix.'booking_url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            $prefix.'external_links' => ['nullable', 'array', new ExternalLinks],
        ];
    }

    /**
     * I campi che il locale scrive solo in modifica: listino, scheda tecnica,
     * organizzatore, SEO.
     *
     * @return array<string, list<mixed>>
     */
    public static function venueEditOnly(string $prefix = ''): array
    {
        return [
            $prefix.'facts' => ['nullable', 'array', new FactRows],
            $prefix.'organizer_name' => ['nullable', 'string', 'max:180'],
            $prefix.'organizer_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            $prefix.'seo' => ['nullable', 'array:title,description,image'],
            $prefix.'seo.title' => ['nullable', 'string', 'max:180'],
            $prefix.'seo.description' => ['nullable', 'string', 'max:400'],
            $prefix.'seo.image' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            $prefix.'ticket_tiers' => ['nullable', 'array', 'max:'.VenueEventEditor::MAX_TIERS],
            $prefix.'ticket_tiers.*.id' => ['nullable', 'integer'],
            $prefix.'ticket_tiers.*.name' => ['required', 'string', 'max:255'],
            $prefix.'ticket_tiers.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            $prefix.'ticket_tiers.*.status' => ['required', Rule::enum(TicketTierStatus::class)],
            $prefix.'ticket_tiers.*.url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            $prefix.'ticket_tiers.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * La prima data e la ripetizione del modulo di creazione.
     *
     * @return array<string, list<mixed>>
     */
    public static function firstDate(string $prefix = '', bool $startRequired = false): array
    {
        return [
            $prefix.'starts_at' => [$startRequired ? 'required' : 'nullable', 'date', ...($startRequired ? [] : ['required_with:'.$prefix.'ends_at'])],
            $prefix.'ends_at' => ['nullable', 'date', 'after:'.$prefix.'starts_at'],
        ];
    }

    /**
     * «Si ripete» nella creazione del locale (`RecurrenceForm::fields`).
     *
     * @return array<string, list<mixed>>
     */
    public static function repeat(string $prefix = ''): array
    {
        return [
            $prefix.'repeat' => ['nullable', 'array:frequency,weekdays,until'],
            $prefix.'repeat.frequency' => ['required_with:'.$prefix.'repeat', Rule::enum(RecurrenceFrequency::class)],
            $prefix.'repeat.weekdays' => ['nullable', 'array', 'max:7'],
            $prefix.'repeat.weekdays.*' => ['distinct', Rule::enum(Weekday::class)],
            $prefix.'repeat.until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
