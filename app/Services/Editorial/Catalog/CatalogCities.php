<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Models\City;
use App\Models\User;
use App\Support\Catalog\CatalogAudit;
use App\Support\CitySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Le città (R-CT-06), come `CityResource`: colonne nome, provincia, fuso,
 * chiusura della giornata notturna, «inizia tra poco», locali, eventi,
 * attiva; filtro attiva; ordine per nome. Il modulo ha le sezioni del
 * pannello: identità, territorio (centro, zoom, raggio, confini), tempo
 * (fuso, chiusura della notte, «inizia tra poco», lingua), funzioni (attiva
 * e interruttori), più informazioni e anteprima su Google.
 *
 * Fuso e chiusura della notte spostano tutto il motore temporale, e una
 * città nuova attiva compare subito nel sito: dall'app creare e modificare
 * una città chiede la password recente (decisione 4, `catalog.cities.store`
 * e `catalog.cities.update`); ogni modifica va nel registro di sicurezza
 * (`CatalogAudit::cityChanged`, anche dal pannello). Il cambio di indirizzo lascia il suo
 * reindirizzamento con il jolly (`CityObserver`), come dal pannello.
 */
final class CatalogCities extends CatalogSection
{
    public function model(): string
    {
        return City::class;
    }

    public function filters(): array
    {
        return ['is_active'];
    }

    protected function listQuery(): Builder
    {
        return City::query()->withCount(['venues', 'events']);
    }

    protected function searchColumns(): array
    {
        return ['name', 'province_name'];
    }

    protected function sorts(): array
    {
        return ['name' => 'name'];
    }

    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function row(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            'id' => $record->id,
            'name' => (string) $record->name,
            'slug' => (string) $record->slug,
            'province_name' => (string) $record->province_name,
            'timezone' => (string) $record->timezone,
            'night_cutoff_time' => self::time($record->night_cutoff_time),
            'starting_soon_minutes' => (int) $record->starting_soon_minutes,
            'venues_count' => (int) ($record->venues_count ?? 0),
            'events_count' => (int) ($record->events_count ?? 0),
            'is_active' => (bool) $record->is_active,
            'web_path' => $this->webPath($record),
            'abilities' => $this->abilities($record, $user),
        ];
    }

    public function form(Model $record, User $user): array
    {
        $record = self::record($record);
        $record->loadCount(['venues', 'events']);
        $bounds = is_array($record->bounds) ? $record->bounds : [];

        return [
            ...$this->row($record, $user),
            'province_code' => (string) $record->province_code,
            'region' => (string) $record->region,
            'country_code' => (string) $record->country_code,
            'center_lat' => (float) $record->center_lat,
            'center_lng' => (float) $record->center_lng,
            'default_zoom' => (int) $record->default_zoom,
            'radius_km' => (int) $record->radius_km,
            'bounds' => [
                'north' => self::coordinate($bounds['north'] ?? null),
                'south' => self::coordinate($bounds['south'] ?? null),
                'east' => self::coordinate($bounds['east'] ?? null),
                'west' => self::coordinate($bounds['west'] ?? null),
            ],
            'locale' => (string) $record->locale,
            'settings' => CitySettings::toRows($record->settings),
            ...CatalogContent::form($record, taxonomy: false),
        ];
    }

    protected function rules(?Model $record, bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('cities', 'slug')->ignore($record?->getKey())],
            'province_code' => ['required', 'string', 'max:4'],
            'province_name' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'max:2'],
            'center_lat' => ['required', 'numeric', 'between:-90,90'],
            'center_lng' => ['required', 'numeric', 'between:-180,180'],
            'default_zoom' => ['required', 'integer', 'between:1,20'],
            'radius_km' => ['required', 'integer', 'between:1,500'],
            'bounds' => ['nullable', 'array:north,south,east,west'],
            'bounds.north' => ['nullable', 'numeric'],
            'bounds.south' => ['nullable', 'numeric'],
            'bounds.east' => ['nullable', 'numeric'],
            'bounds.west' => ['nullable', 'numeric'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'night_cutoff_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'starting_soon_minutes' => ['required', 'integer', 'between:5,1440'],
            'locale' => ['required', 'string', Rule::in(City::LOCALES)],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array', 'list'],
            'settings.*' => ['array:key,enabled'],
            'settings.*.key' => ['required', 'string', 'max:64'],
            'settings.*.enabled' => ['sometimes', 'boolean'],
            ...CatalogContent::rules(taxonomy: false),
        ];
    }

    protected function attributes(): array
    {
        return [
            'name' => (string) __('admin.fields.name'),
            'slug' => (string) __('admin.fields.slug'),
            'province_code' => (string) __('admin.fields.province_code'),
            'province_name' => (string) __('admin.fields.province_name'),
            'region' => (string) __('admin.fields.region'),
            'country_code' => (string) __('admin.fields.country_code'),
            'center_lat' => (string) __('admin.fields.center_lat'),
            'center_lng' => (string) __('admin.fields.center_lng'),
            'default_zoom' => (string) __('admin.fields.default_zoom'),
            'radius_km' => (string) __('admin.fields.radius_km'),
            'bounds' => (string) __('admin.fields.bounds'),
            'bounds.north' => (string) __('admin.compass.north'),
            'bounds.south' => (string) __('admin.compass.south'),
            'bounds.east' => (string) __('admin.compass.east'),
            'bounds.west' => (string) __('admin.compass.west'),
            'timezone' => (string) __('admin.fields.timezone'),
            'night_cutoff_time' => (string) __('admin.fields.night_cutoff_time'),
            'starting_soon_minutes' => (string) __('admin.fields.starting_soon_minutes'),
            'locale' => (string) __('admin.fields.locale'),
            'is_active' => (string) __('admin.fields.is_active'),
            'settings' => (string) __('admin.fields.settings'),
            'settings.*.key' => (string) __('admin.fields.setting_key'),
            ...CatalogContent::attributes(),
        ];
    }

    protected function fill(Model $record, array $state, User $actor): void
    {
        $record = self::record($record);
        foreach (['name', 'slug', 'province_code', 'province_name', 'region', 'country_code', 'center_lat', 'center_lng', 'default_zoom', 'radius_km', 'timezone', 'starting_soon_minutes', 'locale'] as $field) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, self::blankToNull($state[$field]));
            }
        }
        if (array_key_exists('night_cutoff_time', $state)) {
            // Il selettore dell'ora del pannello è senza secondi: la colonna `time` li vuole a zero.
            $record->setAttribute('night_cutoff_time', substr((string) $state['night_cutoff_time'], 0, 5).':00');
        }
        if (array_key_exists('bounds', $state)) {
            $bounds = is_array($state['bounds']) ? $state['bounds'] : [];
            // La forma che salva il gruppo di campi del pannello: le quattro chiavi, anche vuote.
            $record->setAttribute('bounds', array_map(
                static fn (string $side): ?float => is_numeric($bounds[$side] ?? null) ? (float) $bounds[$side] : null,
                ['north' => 'north', 'south' => 'south', 'east' => 'east', 'west' => 'west'],
            ));
        }
        if (array_key_exists('is_active', $state)) {
            $record->setAttribute('is_active', (bool) $state['is_active']);
        } elseif (! $record->exists) {
            $record->setAttribute('is_active', false);
        }
        if (array_key_exists('settings', $state)) {
            $record->setAttribute('settings', CitySettings::toMap($state['settings']));
        }
        CatalogContent::fill($record, $state, $actor);
    }

    /** La modifica nel registro di sicurezza, come dal pannello (`EditCity::afterSave`). */
    protected function updated(Model $record, User $actor): void
    {
        CatalogAudit::cityChanged(self::record($record), $actor);
    }

    private static function time(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }

    private static function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** La voce di questa sezione: un altro modello qui è un errore di chi chiama. */
    private static function record(Model $record): City
    {
        if (! $record instanceof City) {
            throw new \InvalidArgumentException('Atteso '.City::class.', ricevuto '.$record::class.'.');
        }

        return $record;
    }
}
