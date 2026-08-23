<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\EventFilters;
use App\DTOs\PriceConstraint;
use App\Enums\ApiEventSort;
use App\Enums\DatePreset;
use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * I parametri di `GET /v1/events` (§13.2), che valgono anche per gli eventi di
 * un locale e per i marcatori della mappa.
 *
 * La richiesta non interroga niente e non decide niente: costruisce un
 * `EventFilters` — lo stesso oggetto che usa il sito — più le tre cose che
 * l'API ha in più (rettangolo, ordinamento suo, `updated_since`). Le finestre
 * temporali restano di `EventOccurrenceQuery` attraverso `DatePreset`, che è
 * anche il motivo per cui `preset=ongoing` e `preset=starting_soon` non hanno
 * qui alcuna riga di codice propria.
 */
class EventQueryRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'preset' => ['nullable', Rule::in(DatePreset::values())],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'categories' => ['nullable'],
            'categories.*' => ['string', 'max:255'],
            'tags' => ['nullable'],
            'tags.*' => ['string', 'max:255'],
            'price' => ['nullable', 'string', 'max:32', $this->priceRule()],
            'venue' => ['nullable', 'string', 'max:255'],
            'time_of_day' => ['nullable', Rule::in(TimeOfDay::values())],
            'bbox' => ['nullable', 'string', 'max:100', $this->bboxRule()],
            'near' => ['nullable', 'string', 'max:64', $this->nearRule()],
            'radius_km' => ['nullable', 'numeric', 'between:0.1,200'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(ApiEventSort::values())],
            'updated_since' => ['nullable', 'date'],
        ];
    }

    /**
     * I filtri nella stessa forma che usa il sito: è ciò che garantisce che
     * `/eventi?date=tonight&price=free` e `/v1/events?preset=tonight&price=free`
     * rispondano con lo stesso insieme di date.
     *
     * Il prezzo non entra qui perché l'API ne accetta anche uno parametrico
     * (`max:20`) che `PriceFilter` non sa esprimere, e l'ordinamento nemmeno
     * perché i due vocabolari sono diversi: entrambi si applicano dopo, sulla
     * stessa query.
     */
    public function filters(): EventFilters
    {
        [$lat, $lng] = $this->position();

        return new EventFilters(
            preset: $this->preset(),
            date: $this->day('date'),
            from: $this->day('from'),
            to: $this->day('to'),
            categories: $this->slugs('categories'),
            tags: $this->slugs('tags'),
            time: $this->timeOfDay(),
            venue: $this->text('venue'),
            lat: $lat,
            lng: $lng,
            radius: $this->radius(),
            q: $this->text('q') ?? '',
        );
    }

    public function preset(): ?DatePreset
    {
        $value = $this->validated('preset');

        return is_string($value) ? DatePreset::tryFrom($value) : null;
    }

    public function sort(): ?ApiEventSort
    {
        $value = $this->validated('sort');

        return is_string($value) ? ApiEventSort::tryFrom($value) : null;
    }

    public function price(): ?PriceConstraint
    {
        $value = $this->validated('price');

        return is_string($value) ? PriceConstraint::parse($value) : null;
    }

    /**
     * Il rettangolo di §13.3, nell'ordine `minLng,minLat,maxLng,maxLat`.
     *
     * @return array{min_lng: float, min_lat: float, max_lng: float, max_lat: float}|null
     */
    public function bounds(): ?array
    {
        $value = $this->validated('bbox');

        if (! is_string($value)) {
            return null;
        }

        $parts = array_map(floatval(...), explode(',', $value));

        return [
            'min_lng' => $parts[0],
            'min_lat' => $parts[1],
            'max_lng' => $parts[2],
            'max_lat' => $parts[3],
        ];
    }

    public function updatedSince(): ?CarbonImmutable
    {
        $value = $this->validated('updated_since');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function position(): array
    {
        $value = $this->validated('near');

        if (! is_string($value)) {
            return [null, null];
        }

        $parts = array_map(floatval(...), explode(',', $value));

        return [$parts[0], $parts[1]];
    }

    private function radius(): ?float
    {
        $value = $this->validated('radius_km');

        return is_numeric($value) ? (float) $value : null;
    }

    private function timeOfDay(): ?TimeOfDay
    {
        $value = $this->validated('time_of_day');

        return is_string($value) ? TimeOfDay::tryFrom($value) : null;
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay() : null;
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * `categories[]=musica&categories[]=teatro` e `categories=musica,teatro`
     * sono la stessa richiesta: la prima forma è quella di §13.2, la seconda
     * è quella che scrive chi compone un URL a mano.
     *
     * @return list<string>
     */
    private function slugs(string $key): array
    {
        $value = $this->validated($key);

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

    private function priceRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && PriceConstraint::parse($value) === null) {
                $fail(__('validation.in', ['attribute' => $attribute]));
            }
        };
    }

    /**
     * Quattro numeri, longitudine prima, e un rettangolo che non sia rovesciato.
     */
    private function bboxRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $parts = explode(',', $value);

            if (count($parts) !== 4 || array_filter($parts, is_numeric(...)) !== $parts) {
                $fail(__('validation.regex', ['attribute' => $attribute]));

                return;
            }

            [$minLng, $minLat, $maxLng, $maxLat] = array_map(floatval(...), $parts);

            $valid = $minLng >= -180 && $maxLng <= 180 && $minLat >= -90 && $maxLat <= 90
                && $minLng < $maxLng && $minLat < $maxLat;

            if (! $valid) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }

    private function nearRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $parts = explode(',', $value);

            if (count($parts) !== 2 || array_filter($parts, is_numeric(...)) !== $parts) {
                $fail(__('validation.regex', ['attribute' => $attribute]));

                return;
            }

            [$lat, $lng] = array_map(floatval(...), $parts);

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }
}
