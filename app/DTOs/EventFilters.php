<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccessibilityFeature;
use App\Enums\DatePreset;
use App\Enums\EventSort;
use App\Enums\PriceFilter;
use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;

/**
 * Lo stato di una lista pubblica, cioè esattamente ciò che sta nella query
 * string (§11.3): `/eventi?date=tonight&category=musica-dal-vivo&price=free`.
 *
 * L'oggetto è immutabile e sa rigenerare la propria query string. È questo che
 * rende l'URL condivisibile, indicizzabile e riproducibile: la pagina non ha
 * alcuno stato che non stia nell'indirizzo, e ogni pillola di filtro è un
 * semplice link a un altro `EventFilters`.
 *
 * Qui non si interroga il database e non si calcolano date: la traduzione in
 * query la fa `App\Services\Search\EventFinder` passando dal motore temporale.
 */
final readonly class EventFilters
{
    /**
     * @param  list<string>  $categories  slug di categoria
     * @param  list<string>  $tags  slug di tag
     * @param  list<string>  $access  voci di `AccessibilityFeature` richieste tutte insieme
     */
    public function __construct(
        public ?DatePreset $preset = null,
        public ?CarbonImmutable $date = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public array $categories = [],
        public array $tags = [],
        public ?PriceFilter $price = null,
        public ?TimeOfDay $time = null,
        public ?string $municipality = null,
        public ?string $zone = null,
        public ?string $venue = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?float $radius = null,
        public bool $accessible = false,
        public array $access = [],
        public bool $outdoor = false,
        public bool $family = false,
        public ?EventSort $sort = null,
        public string $q = '',
        public ?int $budget = null,
        public bool $discovery = false,
    ) {}

    /**
     * Costruisce i filtri da una richiesta **già validata**: qui i valori si
     * assumono legittimi, la validazione sta in `EventFilterRequest`.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            preset: self::enum(DatePreset::class, $input['date'] ?? null),
            date: self::date($input['date'] ?? null),
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
            categories: self::slugs($input['category'] ?? null),
            tags: self::slugs($input['tag'] ?? null),
            price: self::enum(PriceFilter::class, $input['price'] ?? null),
            time: self::enum(TimeOfDay::class, $input['time'] ?? null),
            municipality: self::text($input['municipality'] ?? null),
            zone: self::text($input['zone'] ?? null),
            venue: self::text($input['venue'] ?? null),
            lat: self::number($input['lat'] ?? null),
            lng: self::number($input['lng'] ?? null),
            radius: self::number($input['radius'] ?? null),
            accessible: self::flag($input['accessible'] ?? null),
            access: self::features($input['access'] ?? null),
            outdoor: self::flag($input['outdoor'] ?? null),
            family: self::flag($input['family'] ?? null),
            sort: self::enum(EventSort::class, $input['sort'] ?? null),
            q: self::text($input['q'] ?? null) ?? '',
            budget: isset($input['budget']) ? (int) $input['budget'] : null,
            discovery: self::flag($input['discovery'] ?? false),
        );
    }

    /**
     * La query string corrispondente, senza le voci vuote: due filtri uguali
     * producono lo stesso URL, che è ciò che rende sensato il canonical.
     *
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        $parameters = [
            'date' => $this->dateParameter(),
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'category' => implode(',', $this->categories),
            'tag' => implode(',', $this->tags),
            'price' => $this->price?->value,
            'time' => $this->time?->value,
            'municipality' => $this->municipality,
            'zone' => $this->zone,
            'venue' => $this->venue,
            'lat' => $this->lat === null ? null : (string) $this->lat,
            'lng' => $this->lng === null ? null : (string) $this->lng,
            'radius' => $this->radius === null ? null : (string) $this->radius,
            'accessible' => $this->accessible ? '1' : null,
            'access' => implode(',', $this->access),
            'outdoor' => $this->outdoor ? '1' : null,
            'family' => $this->family ? '1' : null,
            'sort' => $this->sort?->value,
            'q' => $this->q === '' ? null : $this->q,
            'budget' => $this->budget === null ? null : (string) $this->budget,
            'discovery' => $this->discovery ? '1' : null,
        ];

        return array_filter(
            $parameters,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * Quanti filtri l'utente ha davvero acceso. Governa l'etichetta "3 filtri
     * attivi" e la scelta di indicizzare o no la combinazione.
     */
    public function activeCount(): int
    {
        $parameters = $this->toQueryString();
        unset($parameters['sort'], $parameters['discovery'], $parameters['lat'], $parameters['lng'], $parameters['radius']);

        return count($parameters) + ($this->hasPosition() ? 1 : 0);
    }

    public function isEmpty(): bool
    {
        return $this->toQueryString() === [];
    }

    public function hasPosition(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function hasDateWindow(): bool
    {
        return $this->preset !== null || $this->date !== null || $this->from !== null || $this->to !== null;
    }

    // ------------------------------------------------------ varianti immutabili

    public function withPreset(?DatePreset $preset): self
    {
        return $this->copy(['preset' => $preset, 'date' => null, 'from' => null, 'to' => null]);
    }

    public function withDate(?CarbonImmutable $date): self
    {
        return $this->copy(['date' => $date, 'preset' => null, 'from' => null, 'to' => null]);
    }

    public function withPrice(?PriceFilter $price): self
    {
        return $this->copy(['price' => $price]);
    }

    public function withTime(?TimeOfDay $time): self
    {
        return $this->copy(['time' => $time]);
    }

    public function withSort(?EventSort $sort): self
    {
        return $this->copy(['sort' => $sort]);
    }

    public function withMunicipality(?string $municipality): self
    {
        return $this->copy(['municipality' => $municipality]);
    }

    public function withZone(?string $zone): self
    {
        return $this->copy(['zone' => $zone]);
    }

    /**
     * Le voci di accessibilità richieste. Sono in **AND**: chi chiede ingresso
     * senza scalini e servizi accessibili sta dicendo che gli servono
     * entrambi, non che gli basta uno dei due.
     *
     * @param  list<string>  $features
     */
    public function withAccess(array $features): self
    {
        $valid = array_values(array_filter(
            array_unique($features),
            static fn (string $feature): bool => AccessibilityFeature::tryFrom($feature) !== null,
        ));

        return $this->copy(['access' => $valid]);
    }

    public function toggleAccess(string $feature): self
    {
        return $this->withAccess($this->toggle($this->access, $feature));
    }

    public function hasAccess(string $feature): bool
    {
        return in_array($feature, $this->access, true);
    }

    public function withVenue(?string $venue): self
    {
        return $this->copy(['venue' => $venue]);
    }

    public function withSearch(string $q): self
    {
        return $this->copy(['q' => $q]);
    }

    public function withAccessible(bool $value): self
    {
        return $this->copy(['accessible' => $value]);
    }

    public function withOutdoor(bool $value): self
    {
        return $this->copy(['outdoor' => $value]);
    }

    public function withFamily(bool $value): self
    {
        return $this->copy(['family' => $value]);
    }

    public function withPosition(?float $lat, ?float $lng, ?float $radius): self
    {
        return $this->copy([
            'lat' => $lat, 'lng' => $lng, 'radius' => $radius,
            'sort' => ($lat === null || $lng === null) && $this->sort === EventSort::Distance ? null : $this->sort,
        ]);
    }

    /**
     * @param  list<string>  $categories
     */
    public function withCategories(array $categories): self
    {
        return $this->copy(['categories' => array_values(array_unique($categories))]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): self
    {
        return $this->copy(['tags' => array_values(array_unique($tags))]);
    }

    public function toggleCategory(string $slug): self
    {
        return $this->withCategories($this->toggle($this->categories, $slug));
    }

    public function toggleTag(string $slug): self
    {
        return $this->withTags($this->toggle($this->tags, $slug));
    }

    public function hasCategory(string $slug): bool
    {
        return in_array($slug, $this->categories, true);
    }

    public function hasTag(string $slug): bool
    {
        return in_array($slug, $this->tags, true);
    }

    /**
     * Tutto azzerato tranne la posizione: chi ha già concesso la posizione non
     * deve riconcederla per aver tolto un filtro.
     */
    public function cleared(): self
    {
        return new self(lat: $this->lat, lng: $this->lng, radius: $this->radius);
    }

    // ---------------------------------------------------------------- interno

    /**
     * L'unico punto in cui si costruisce una variante: le proprietà sono
     * `readonly` e PHP non sa ancora clonarne una cambiando un campo.
     *
     * @param  array{preset?: DatePreset|null, date?: CarbonImmutable|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, categories?: list<string>, tags?: list<string>, price?: PriceFilter|null, time?: TimeOfDay|null, municipality?: string|null, zone?: string|null, venue?: string|null, lat?: float|null, lng?: float|null, radius?: float|null, accessible?: bool, access?: list<string>, outdoor?: bool, family?: bool, sort?: EventSort|null, q?: string}  $overrides
     */
    private function copy(array $overrides): self
    {
        $arguments = [
            'preset' => $this->preset,
            'date' => $this->date,
            'from' => $this->from,
            'to' => $this->to,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'price' => $this->price,
            'time' => $this->time,
            'municipality' => $this->municipality,
            'zone' => $this->zone,
            'venue' => $this->venue,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'radius' => $this->radius,
            'accessible' => $this->accessible,
            'access' => $this->access,
            'outdoor' => $this->outdoor,
            'family' => $this->family,
            'sort' => $this->sort,
            'q' => $this->q,
            'budget' => $this->budget,
            'discovery' => $this->discovery,
            ...$overrides,
        ];

        return new self(...$arguments);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function toggle(array $values, string $slug): array
    {
        return in_array($slug, $values, true)
            ? array_values(array_filter($values, static fn (string $value): bool => $value !== $slug))
            : [...$values, $slug];
    }

    /**
     * `date` porta sia i preset sia una data puntuale: `today` e `2026-09-05`
     * abitano lo stesso parametro perché per chi legge sono la stessa domanda.
     *
     * @template T of DatePreset|PriceFilter|TimeOfDay|EventSort
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): mixed
    {
        return is_string($value) ? $enum::tryFrom($value) : null;
    }

    /**
     * Il preset e la data puntuale abitano lo stesso parametro perché per chi
     * cerca sono la stessa domanda: "quando".
     */
    private function dateParameter(): ?string
    {
        if ($this->preset !== null) {
            return $this->preset->value;
        }

        return $this->date?->format('Y-m-d');
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    /**
     * @return list<string>
     */
    private static function slugs(mixed $value): array
    {
        $values = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => [],
        };

        $slugs = [];

        foreach ($values as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);

            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Le voci di accessibilità arrivano come `access=step_free_entrance,accessible_toilets`.
     * Quelle che non esistono nell'enum spariscono invece di far fallire la
     * pagina: un indirizzo condiviso mesi fa non deve rompersi perché una voce
     * è stata rinominata.
     *
     * @return list<string>
     */
    private static function features(mixed $value): array
    {
        return array_values(array_filter(
            self::slugs($value),
            static fn (string $slug): bool => AccessibilityFeature::tryFrom($slug) !== null,
        ));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
