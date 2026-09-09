<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\DTOs\EventFilters;
use App\Enums\DatePreset;
use App\Enums\EventSort;
use App\Enums\PriceFilter;
use App\Enums\TimeOfDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validazione della query string delle liste pubbliche (§11.3).
 *
 * Un parametro sbagliato non è un errore da mostrare a chi legge: un link
 * vecchio o storpiato deve continuare a rendere una pagina sensata. Per questo
 * i valori non riconosciuti vengono **scartati** prima della validazione
 * invece di produrre un 422, e `date` accetta sia i preset sia una data
 * puntuale perché per chi cerca sono la stessa domanda.
 */
class EventFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', Rule::in(PriceFilter::values())],
            'time' => ['nullable', Rule::in(TimeOfDay::values())],
            'municipality' => ['nullable', 'string', 'max:120'],
            'zone' => ['nullable', 'string', 'max:120'],
            'venue' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'between:0.1,200'],
            'accessible' => ['nullable', 'boolean'],
            'access' => ['nullable', 'string', 'max:255'],
            'outdoor' => ['nullable', 'boolean'],
            'family' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(EventSort::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'budget' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'discovery' => ['nullable', 'boolean'],
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
        ];
    }

    /**
     * Toglie di mezzo ciò che non è riconoscibile: un `price=pizza` arrivato da
     * un link malfatto vale come "nessun filtro sul prezzo", non come errore.
     */
    protected function prepareForValidation(): void
    {
        $date = $this->query('date');

        if (is_string($date) && DatePreset::tryFrom($date) === null && ! $this->isCalendarDate($date)) {
            $this->query->remove('date');
        }

        foreach (['price' => PriceFilter::values(), 'time' => TimeOfDay::values(), 'sort' => EventSort::values()] as $key => $allowed) {
            $value = $this->query($key);

            if (is_string($value) && ! in_array($value, $allowed, true)) {
                $this->query->remove($key);
            }
        }

        foreach (['from', 'to'] as $key) {
            $value = $this->query($key);

            if (is_string($value) && ! $this->isCalendarDate($value)) {
                $this->query->remove($key);
            }
        }

        foreach (['lat', 'lng', 'radius'] as $key) {
            if (! is_numeric($this->query($key)) && $this->query->has($key)) {
                $this->query->remove($key);
            }
        }

        /*
         * Il modulo manda `access[]=…` una casella per volta; l'indirizzo
         * canonico che `EventFilters::toQueryString()` produce è invece
         * `access=a,b`. Le due forme si incontrano qui, così che il resto
         * della catena ne conosca una sola.
         */
        $access = $this->query('access');

        if (is_array($access)) {
            $this->query->set('access', implode(',', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $access,
            )));
        }

        foreach (['accessible', 'outdoor', 'family'] as $key) {
            $value = $this->query($key);

            if ($value !== null && ! in_array((string) (is_scalar($value) ? $value : ''), ['0', '1', 'true', 'false'], true)) {
                $this->query->remove($key);
            }
        }
    }

    private function isCalendarDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    public function filters(): EventFilters
    {
        return EventFilters::fromArray($this->validated());
    }
}
