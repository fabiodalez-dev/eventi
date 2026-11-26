<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Actions\UpdateVenueProfile;
use App\Enums\AccessibilityFeature;
use App\Enums\AgeGroup;
use App\Enums\ContactMode;
use App\Enums\MembershipRequirement;
use App\Http\Resources\V1\Management\VenueProfileResource;
use App\Models\City;
use App\Models\Venue;
use App\Rules\FactRows;
use App\Rules\TransitRows;
use App\Services\Events\EventFormRules;
use App\Support\Management\ManagementSubject;
use App\Support\PracticalIcons;
use App\Support\VenueClosures;
use App\Support\VenueGeography;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * La scheda del locale dall'app: `GET` e `PATCH V/profile`.
 *
 * Solo il referente (`VenuePolicy::update`), come la pagina «Il tuo locale».
 *
 * Il `PATCH` è per sezione: l'app manda le chiavi di una sezione alla volta e
 * si convalidano solo quelle (`sometimes`). Le regole sono quelle che nel
 * pannello stanno nello schema Filament, riscritte qui una per una; un test
 * di parità manda gli stessi valori sbagliati alla pagina e all'API. Le
 * regole che vivono già fuori dallo schema (righe di «Come arrivare» e della
 * scheda, chiusure) sono le stesse classi.
 */
final class VenueProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->venue()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if (! $this->isMethod('patch')) {
            return [];
        }

        return self::fieldRules($this->venue()->city);
    }

    /**
     * Le regole dei campi della scheda, una per campo, per la città del
     * locale (i comuni ammessi sono quelli della sua provincia). Le usa anche
     * la scheda del locale della redazione (`E/venues/{venue}`), che scrive
     * gli stessi campi con `UpdateVenueProfile`.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(?City $city): array
    {
        return [
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],

            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'address_extra' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'municipality' => ['sometimes', 'required', 'string', Rule::in(VenueGeography::municipalities($city))],
            // Obbligatorio per Padova: lo controlla `after()`, anche quando la chiave manca.
            'zone' => ['sometimes', 'nullable', 'string', Rule::in(VenueGeography::districts())],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            'opening_hours' => ['sometimes', 'nullable', 'array', 'list', 'max:'.VenueProfileResource::OPENING_HOURS_MAX],
            'opening_hours.*' => ['array'],
            'opening_hours.*.day' => ['required', Rule::in(VenueProfileResource::WEEKDAYS)],
            'opening_hours.*.open' => ['required', 'date_format:H:i'],
            'opening_hours.*.close' => ['required', 'date_format:H:i'],
            // Le stesse regole di `VenueClosures::normalize`, qui per avere l'errore sul campo giusto.
            'closures' => ['sometimes', 'nullable', 'array', 'list', 'max:'.VenueClosures::MAX],
            'closures.*' => ['array'],
            'closures.*.from' => ['required', 'date_format:Y-m-d'],
            'closures.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:closures.*.from'],
            'closures.*.note' => ['nullable', 'string', 'max:120'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()\/.\-]{5,40}$/'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'socials' => ['sometimes', 'nullable', 'array', 'list', 'max:'.VenueProfileResource::SOCIALS_MAX],
            'socials.*' => ['array:name,url'],
            'socials.*.name' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN _-]+$/u', 'distinct'],
            'socials.*.url' => ['nullable', 'string', 'url:http,https', 'max:2048'],

            'transit' => ['sometimes', 'nullable', 'array', 'list', new TransitRows],
            'accessibility' => ['sometimes', 'nullable', 'array:'.implode(',', array_column(AccessibilityFeature::cases(), 'value'))],
            'accessibility.*' => ['nullable', 'boolean'],
            'info' => ['sometimes', 'nullable', 'array', 'list', new FactRows],
            'requires_membership' => ['sometimes', 'boolean'],
            'membership_notes' => ['sometimes', 'nullable', 'string', 'max:50000'],

            'content_details' => ['sometimes', 'array:'.implode(',', VenueProfileResource::CONTENT_KEYS)],
            // Prima di andare (`BeforeGoingFields::make(true)`)
            'content_details.age_groups' => ['nullable', 'array', 'list'],
            'content_details.age_groups.*' => [Rule::enum(AgeGroup::class)],
            'content_details.stroller' => ['nullable', Rule::in(['yes', 'no'])],
            'content_details.changing_table' => ['nullable', Rule::in(['yes', 'no'])],
            'content_details.kids_area' => ['nullable', Rule::in(['yes', 'no'])],
            'content_details.membership' => ['nullable', Rule::enum(MembershipRequirement::class)],
            'content_details.accessibility' => ['nullable', Rule::in(['yes', 'no'])],
            'content_details.membership_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.accessibility_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.feature_ids' => ['nullable', 'array', 'list', 'max:'.EventFormRules::MAX_FEATURES],
            'content_details.feature_ids.*' => ['integer', 'distinct', Rule::exists('event_features', 'id')->where('is_active', true)->where('is_system', false)],
            'content_details.practical_custom' => ['nullable', 'array', 'list', 'max:12'],
            'content_details.practical_custom.*' => ['array:label,icon,text'],
            'content_details.practical_custom.*.label' => ['required', 'string', 'max:120'],
            'content_details.practical_custom.*.icon' => ['required', Rule::in(array_keys(PracticalIcons::options()))],
            'content_details.practical_custom.*.text' => ['nullable', 'string', 'max:1000'],
            'content_details.food_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.start_notes' => ['nullable', 'string', 'max:2000'],
            // Informazioni editoriali (`EditorialFields::content(false, true)`)
            'content_details.introduction' => ['nullable', 'string', 'max:5000'],
            'content_details.parking_type' => ['nullable', Rule::in(['free', 'paid', 'none'])],
            'content_details.parking_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.transit_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.entrance_notes' => ['nullable', 'string', 'max:2000'],
            'content_details.faqs' => ['nullable', 'array', 'list', 'max:30'],
            'content_details.faqs.*' => ['array:question,answer'],
            'content_details.faqs.*.question' => ['required', 'string', 'max:250'],
            'content_details.faqs.*.answer' => ['required', 'string', 'max:5000'],
            // SEO (`EditorialFields::seo()`): l'indicizzazione resta della redazione.
            'seo' => ['sometimes', 'array:title,description,image'],
            'seo.title' => ['nullable', 'string', 'max:180'],
            'seo.description' => ['nullable', 'string', 'max:400'],
            'seo.image' => ['nullable', 'string', 'url', 'max:2048'],

            // Modulo di contatto (`ContactFields`)
            'contact_mode' => ['sometimes', 'required', Rule::enum(ContactMode::class)],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    /** I nomi dei campi come li legge chi compila, uguali alle etichette del pannello. */
    public function attributes(): array
    {
        return self::fieldAttributes();
    }

    /** @return array<string, string> */
    public static function fieldAttributes(): array
    {
        return [
            'short_description' => __('manage.fields.short_description'),
            'description' => __('manage.fields.description'),
            'address' => __('manage.fields.address'),
            'address_extra' => __('manage.fields.address_extra'),
            'postal_code' => __('manage.fields.postal_code'),
            'municipality' => __('tonight.municipality'),
            'zone' => __('tonight.district'),
            'capacity' => __('manage.fields.capacity'),
            'phone' => __('manage.fields.phone'),
            'email' => __('manage.fields.email'),
            'website' => __('manage.fields.website'),
            'socials' => __('manage.fields.socials'),
            'opening_hours.*.day' => __('manage.fields.opening_day'),
            'opening_hours.*.open' => __('manage.fields.opening_from'),
            'opening_hours.*.close' => __('manage.fields.opening_to'),
            'closures.*.from' => __('manage.closures.from'),
            'closures.*.to' => __('manage.closures.to'),
            'closures.*.note' => __('manage.closures.note'),
            'contact_mode' => __('contact.mode_question'),
            'contact_email' => __('contact.recipient'),
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isMethod('patch')) {
                return;
            }
            self::checkTogether($validator, $this->all(), $this->venue());
        }];
    }

    /**
     * I controlli che legano due campi, anche quando uno dei due non è nella
     * richiesta: il quartiere per il comune di Padova, il recapito del modulo
     * di contatto quando i messaggi sono accesi.
     *
     * @param  array<string, mixed>  $input
     */
    public static function checkTogether(Validator $validator, array $input, ?Venue $venue): void
    {
        // Il comune di Padova vuole il quartiere, come `VenueGeographyFields::district()`.
        if (array_key_exists('municipality', $input) && VenueGeography::hasDistricts($input['municipality']) && blank($input['zone'] ?? null)) {
            $validator->errors()->add('zone', __('validation.required', ['attribute' => __('tonight.district')]));
        }
        // Messaggi accesi senza un indirizzo che li riceva: come `ContactFields`, il recapito diventa obbligatorio.
        $mode = array_key_exists('contact_mode', $input) ? $input['contact_mode'] : ($venue === null ? ContactMode::Disabled->value : $venue->contact_mode->value);
        $email = array_key_exists('contact_email', $input) ? $input['contact_email'] : $venue?->contact_email;
        if (in_array($mode, [ContactMode::Members->value, ContactMode::Everyone->value], true) && blank($email)) {
            $validator->errors()->add('contact_email', __('validation.required', ['attribute' => __('contact.recipient')]));
        }
    }

    /**
     * I campi convalidati nella forma che `UpdateVenueProfile` riceve dal
     * pannello: i social da coppie a mappa, il quartiere tolto fuori da
     * Padova, l'accessibilità senza le voci «non dichiarato».
     *
     * @return array<string, mixed>
     */
    public function profileState(): array
    {
        return self::normalize($this->validated());
    }

    /**
     * Dalla forma dell'app alla forma di `UpdateVenueProfile`.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function normalize(array $validated): array
    {
        /** @var array<string, mixed> $state */
        $state = array_intersect_key($validated, array_flip(UpdateVenueProfile::FIELDS));

        if (array_key_exists('socials', $state)) {
            $map = [];
            foreach ((array) $state['socials'] as $row) {
                $map[(string) $row['name']] = $row['url'] ?? null;
            }
            $state['socials'] = $map === [] ? null : $map;
        }

        if (array_key_exists('municipality', $state) && ! VenueGeography::hasDistricts($state['municipality'])) {
            $state['zone'] = null;
        }

        if (array_key_exists('accessibility', $state)) {
            $state['accessibility'] = array_filter((array) $state['accessibility'], static fn (mixed $value): bool => $value !== null);
        }

        return $state;
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }
}
