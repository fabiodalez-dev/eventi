<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Actions\UpdateVenueProfile;
use App\Enums\SeoIndexing;
use App\Enums\VenuePlan;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Http\Requests\Api\V1\Management\VenueProfileRequest;
use App\Http\Resources\V1\Management\VenueProfileResource;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\ManagementOptions;
use App\Support\VenueGeography;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Il modulo del locale della redazione (`VenueResource::form`, schede
 * Identità, Dove, Contatti, Prima di andare, Contenuti e SEO, Avanzate,
 * Ticketing, Moderazione) per l'app: `POST` e `PATCH E/venues`.
 *
 * I campi della scheda pubblica sono gli stessi che il referente scrive da
 * «Il tuo locale» e passano dalla stessa regola (`VenueProfileRequest` per la
 * convalida, `UpdateVenueProfile` per il salvataggio: orari, chiusure, testo
 * formattato, colonne divise fra più sezioni). Qui si aggiungono quelli che
 * nel pannello tiene solo la redazione: nome, indirizzo nel sito, città,
 * tipo, sigla della provincia, coordinate obbligatorie, stato, verifica,
 * pubblicazione autonoma, senza scopo di lucro, piano, motivo, ticketing,
 * indicizzazione e contenuto dimostrativo.
 *
 * Come il pannello, il `PATCH` è per sezione: si convalidano e si salvano
 * solo le chiavi ricevute.
 */
final class EditorialVenueEditor
{
    /** I campi che scrive solo la redazione. */
    public const EDITORIAL_FIELDS = [
        'name', 'slug', 'city_id', 'type', 'province_code',
        'status', 'is_verified', 'auto_publish', 'is_nonprofit', 'plan', 'rejection_reason',
        'ticketing_enabled', 'seo_indexing', 'is_demo',
    ];

    public function __construct(private readonly UpdateVenueProfile $profile) {}

    /**
     * Un locale nuovo: i campi obbligatori del modulo del pannello (nome,
     * città, tipo, indirizzo, comune, sigla della provincia, coordinate) più
     * quelli che si vogliono già compilare. Lo stato parte da «bozza» e il
     * piano da «gratuito», come i valori predefiniti del modulo.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor): Venue
    {
        $city = City::query()->find($input['city_id'] ?? null);
        $state = $this->validate($input, null, $city, creating: true);

        return DB::transaction(function () use ($state, $actor, $city): Venue {
            $venue = new Venue;
            $venue->forceFill([
                'status' => VenueStatus::Draft,
                'plan' => VenuePlan::Free,
                'type' => VenueType::Altro,
            ]);
            $this->fillEditorial($venue, $state, $actor);
            // Il profilo ha bisogno della città per orari e chiusure: c'è già sul modello, non ancora salvato.
            $venue->setRelation('city', $city);
            $profile = VenueProfileRequest::normalize($state);
            // `UpdateVenueProfile` salva: la riga nasce qui con tutti i campi insieme.
            $this->profile->handle($venue, $profile);

            return $venue->refresh();
        });
    }

    /**
     * Le chiavi ricevute, di solito una sezione alla volta.
     *
     * Lo stato no: approvare, rifiutare e sospendere passano dalle rotte di
     * moderazione (`ModerateVenueAction`), che chiedono la password recente
     * dove serve e avvisano chi gestisce il locale. Un `status` nel `PATCH`
     * si ignora, come il pannello che nella modifica lo mostra senza
     * salvarlo.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Venue $venue, array $input, User $actor): Venue
    {
        unset($input['status']);
        $city = array_key_exists('city_id', $input) ? City::query()->find($input['city_id']) : $venue->city;
        $state = $this->validate($input, $venue, $city, creating: false);

        return DB::transaction(function () use ($venue, $state, $actor, $city): Venue {
            $this->fillEditorial($venue, $state, $actor);
            if ($city instanceof City) {
                $venue->setRelation('city', $city);
            }
            $this->profile->handle($venue, VenueProfileRequest::normalize($state));

            return $venue->refresh();
        });
    }

    /**
     * Le regole: quelle della scheda pubblica (`VenueProfileRequest`) più
     * quelle dei campi della redazione, con gli obbligatori del pannello
     * (indirizzo, coordinate, sigla della provincia).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Venue $venue, ?City $city, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $rules = array_replace(VenueProfileRequest::fieldRules($city), [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('venues', 'slug')->ignore($venue?->getKey())],
            'city_id' => [$presence, 'required', 'integer', Rule::exists('cities', 'id')],
            'type' => [$presence, 'required', Rule::enum(VenueType::class)],
            'address' => [$presence, 'required', 'string', 'max:255'],
            'municipality' => [$presence, 'required', 'string', Rule::in(VenueGeography::municipalities($city))],
            'province_code' => [$presence, 'required', 'string', 'max:4'],
            'lat' => [$presence, 'required', 'numeric', 'between:-90,90'],
            'lng' => [$presence, 'required', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'required', Rule::enum(VenueStatus::class)],
            'is_verified' => ['sometimes', 'boolean'],
            'auto_publish' => ['sometimes', 'boolean'],
            'is_nonprofit' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', 'required', Rule::enum(VenuePlan::class)],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'ticketing_enabled' => ['sometimes', 'boolean'],
            'seo_indexing' => ['sometimes', 'required', Rule::enum(SeoIndexing::class)],
            'is_demo' => ['sometimes', 'boolean'],
        ]);

        $validator = Validator::make($input, $rules, [], array_replace(VenueProfileRequest::fieldAttributes(), [
            'name' => __('admin.fields.name'),
            'slug' => __('admin.fields.slug'),
            'city_id' => __('admin.fields.city'),
            'type' => __('admin.fields.type'),
            'province_code' => __('admin.fields.province_code'),
            'lat' => __('admin.fields.lat'),
            'lng' => __('admin.fields.lng'),
            'status' => __('admin.fields.status'),
            'plan' => __('admin.fields.plan'),
            'rejection_reason' => __('admin.fields.rejection_reason'),
        ]));
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($input, $venue): void {
            VenueProfileRequest::checkTogether($validator, $input, $venue);
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * I campi della redazione sul modello. Il ticketing lo scrivono solo admin
     * e super admin (nel pannello il campo è spento e non si salva per gli
     * altri); indicizzazione e contenuto dimostrativo li protegge anche
     * `HasEditorialContent`.
     *
     * @param  array<string, mixed>  $state
     */
    private function fillEditorial(Venue $venue, array $state, User $actor): void
    {
        $admin = $actor->hasAnyRole(['admin', 'super_admin']);

        foreach (['name', 'slug', 'city_id', 'type', 'province_code', 'status', 'is_verified', 'auto_publish', 'is_nonprofit', 'plan', 'rejection_reason'] as $field) {
            if (array_key_exists($field, $state)) {
                $venue->setAttribute($field, $field === 'slug' && blank($state[$field]) ? $venue->slug : $state[$field]);
            }
        }
        if ($admin && array_key_exists('ticketing_enabled', $state)) {
            $venue->setAttribute('ticketing_enabled', (bool) $state['ticketing_enabled']);
        }
        if (array_key_exists('seo_indexing', $state)) {
            $seo = is_array($venue->getAttribute('seo')) ? $venue->getAttribute('seo') : [];
            $venue->setAttribute('seo', [...$seo, 'indexing' => $state['seo_indexing']]);
        }
        if (array_key_exists('is_demo', $state)) {
            $venue->setAttribute('is_demo', (bool) $state['is_demo']);
        }
    }

    /**
     * Il modulo per l'app: la scheda pubblica nella forma di «Il tuo locale»
     * (`VenueProfileResource`) più i campi della redazione e le scelte per
     * compilarli.
     *
     * @return array<string, mixed>
     */
    public function form(Venue $venue, User $actor): array
    {
        $venue->loadMissing('city');
        $seo = is_array($venue->getAttribute('seo')) ? $venue->getAttribute('seo') : [];

        return [
            ...VenueProfileResource::toArray($venue),
            'editorial' => [
                'name' => (string) $venue->name,
                'slug' => (string) $venue->slug,
                'city_id' => (int) $venue->city_id,
                'type' => $venue->type->value,
                'province_code' => (string) $venue->province_code,
                'status' => $venue->status->value,
                'is_verified' => (bool) $venue->is_verified,
                'auto_publish' => (bool) $venue->auto_publish,
                'is_nonprofit' => (bool) $venue->is_nonprofit,
                'plan' => $venue->plan->value,
                'rejection_reason' => $venue->rejection_reason,
                'ticketing_enabled' => (bool) $venue->ticketing_enabled,
                'seo_indexing' => (string) ($seo['indexing'] ?? SeoIndexing::Automatic->value),
                'is_demo' => (bool) $venue->getAttribute('is_demo'),
                'can_ticketing' => $actor->hasAnyRole(['admin', 'super_admin']),
            ],
            'editorial_choices' => self::choices(),
            // Gli elenchi delle sezioni della scheda (mezzi, accessibilità, icone, caratteristiche, tetti): gli stessi di `GET /management/options`.
            'profile_options' => app(ManagementOptions::class)->payload(),
        ];
    }

    /**
     * Le scelte dei campi della redazione: città, tipi, stati, piani,
     * indicizzazione.
     *
     * @return array<string, mixed>
     */
    public static function choices(): array
    {
        $cities = City::query()->orderByDesc('is_active')->orderBy('name')->get();

        return [
            'cities' => $cities->map(fn (City $city): array => ['value' => (string) $city->getKey(), 'label' => (string) $city->name])->values()->all(),
            // I comuni ammessi per ogni città (la provincia), per il campo «Comune» quando si cambia città.
            'municipalities' => $cities->mapWithKeys(fn (City $city): array => [(string) $city->getKey() => array_map(
                static fn (string $name): array => ['value' => $name, 'label' => $name],
                VenueGeography::municipalities($city),
            )])->all(),
            // Il centro di ogni città, per «Centra sulla città».
            'centers' => $cities->mapWithKeys(fn (City $city): array => [(string) $city->getKey() => [
                ['value' => 'lat', 'label' => (string) $city->center_lat],
                ['value' => 'lng', 'label' => (string) $city->center_lng],
            ]])->all(),
            'districts' => array_map(static fn (string $name): array => ['value' => $name, 'label' => $name], VenueGeography::districts()),
            'district_municipality' => [['value' => VenueGeography::DISTRICT_MUNICIPALITY, 'label' => VenueGeography::DISTRICT_MUNICIPALITY]],
            'types' => array_map(static fn (VenueType $type): array => ['value' => $type->value, 'label' => $type->label()], VenueType::cases()),
            'statuses' => array_map(static fn (VenueStatus $status): array => ['value' => $status->value, 'label' => $status->label()], VenueStatus::cases()),
            'plans' => array_map(static fn (VenuePlan $plan): array => ['value' => $plan->value, 'label' => $plan->label()], VenuePlan::cases()),
            'seo_indexing' => [
                ['value' => SeoIndexing::Automatic->value, 'label' => (string) __('seo.automatic')],
                ['value' => SeoIndexing::Excluded->value, 'label' => (string) __('seo.excluded')],
            ],
        ];
    }
}
