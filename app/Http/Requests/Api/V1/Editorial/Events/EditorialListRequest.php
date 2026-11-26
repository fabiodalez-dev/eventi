<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Events;

use Illuminate\Foundation\Http\FormRequest;

/**
 * I criteri di un elenco della redazione (contratto delle fondamenta):
 * `page`, `per_page` (24 se manca, al massimo 50: oltre si tronca), `q`,
 * `tab`, `filter[chiave]` (un valore per chiave), `sort` (`campo` o
 * `-campo`). Chi può vedere l'elenco lo decide il controller con la policy.
 */
class EditorialListRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 24;

    public const PER_PAGE_MAX = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
            'tab' => ['nullable', 'string', 'max:40'],
            'filter' => ['nullable', 'array'],
            'filter.*' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) ($this->validated('page') ?? 1));
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        return $value === null ? self::PER_PAGE_DEFAULT : min(self::PER_PAGE_MAX, max(1, (int) $value));
    }

    public function search(): ?string
    {
        $q = trim((string) ($this->validated('q') ?? ''));

        return $q === '' ? null : $q;
    }

    public function tab(): ?string
    {
        $tab = $this->validated('tab');

        return is_string($tab) && $tab !== '' && $tab !== 'all' ? $tab : null;
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        $filters = $this->validated('filter') ?? [];

        return is_array($filters) ? array_filter(array_map(fn (mixed $value): string => (string) $value, $filters), fn (string $value): bool => $value !== '') : [];
    }

    public function sort(): ?string
    {
        $sort = $this->validated('sort');

        return is_string($sort) && $sort !== '' ? $sort : null;
    }
}
