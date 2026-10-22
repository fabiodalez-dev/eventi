<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\Models\SavedSearch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Avvisami per nuovi eventi come questi»: arrivano i filtri come li scrive
 * l'URL di `/eventi` (o il solo termine di `/cerca`) e un nome facoltativo.
 * La finestra temporale viene scartata: una ricerca salvata guarda sempre
 * avanti.
 */
class StoreSavedSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'filters' => ['required', 'array', 'max:30'],
            'filters.*' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) $this->validated('filters', []);

        return SavedSearch::normalise($raw);
    }

    /** Il nome: quello scritto, o un riassunto dei filtri. */
    public function name(): string
    {
        $name = trim((string) $this->validated('name', ''));
        if ($name !== '') {
            return $name;
        }
        $filters = $this->filters();
        $parts = array_filter([
            $filters['q'] ?? null,
            isset($filters['category']) ? str_replace(',', ', ', $filters['category']) : null,
            isset($filters['tag']) ? '#'.str_replace(',', ' #', $filters['tag']) : null,
            isset($filters['price']) ? __('filters.price.'.$filters['price']) : null,
            $filters['municipality'] ?? null,
            $filters['zone'] ?? null,
        ]);

        return mb_substr(implode(' · ', $parts) ?: __('search.saved.default_name'), 0, 120);
    }
}
