<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * I parametri di un elenco della moderazione, con la convenzione degli
 * elenchi della redazione: `page`, `per_page` (oltre il massimo si tronca),
 * `q`, `filter[chiave]`, `sort` (`campo` o `-campo`). Ogni elenco dice quali
 * filtri e quali ordinamenti accetta; un valore sconosciuto è un 422, perché
 * è un difetto del programma che chiede e va corretto lì.
 *
 * Chi può leggere l'elenco lo decide il controller con la policy.
 */
abstract class ModerationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $sorts = [];
        foreach ($this->sorts() as $field) {
            $sorts[] = $field;
            $sorts[] = '-'.$field;
        }

        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', Rule::in($sorts)],
            'filter' => ['nullable', 'array'],
            ...$this->filterRules(),
        ];
    }

    /** @return list<string> */
    abstract protected function sorts(): array;

    /** @return array<string, array<int, mixed>> */
    abstract protected function filterRules(): array;

    public function pageNumber(): int
    {
        return max(1, (int) ($this->validated('page') ?? 1));
    }

    /** Righe per pagina: predefinite quelle dell'API (24), al massimo 50; oltre si tronca. */
    public function perPage(): int
    {
        $asked = $this->validated('per_page');

        return min(config()->integer('api.limits.max'), max(1, $asked === null ? config()->integer('api.limits.default') : (int) $asked));
    }

    public function search(): ?string
    {
        $q = $this->validated('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }

    public function sortField(): ?string
    {
        $sort = $this->validated('sort');

        return is_string($sort) && $sort !== '' ? $sort : null;
    }

    public function filterValue(string $key): ?string
    {
        $value = $this->validated('filter.'.$key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function filterFlag(string $key): bool
    {
        return filter_var($this->validated('filter.'.$key), FILTER_VALIDATE_BOOLEAN);
    }
}
