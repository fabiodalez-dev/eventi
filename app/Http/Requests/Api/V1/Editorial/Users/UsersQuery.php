<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Models\User;
use App\Services\Users\UserDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'elenco degli account (`GET E/users`): la convenzione degli elenchi della
 * redazione con le otto schede e i dieci filtri del pannello. Lo vede tutta
 * la redazione (`UserPolicy::viewAny`), come la voce del menu.
 */
final class UsersQuery extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:200'],
            'tab' => ['nullable', 'string', Rule::in(UserDirectory::TABS)],
            'sort' => ['nullable', 'string', 'max:40'],
            'filter' => ['nullable', 'array:'.implode(',', UserDirectory::FILTERS)],
            'filter.*' => ['nullable', 'string', 'max:40'],
            'filter.trashed' => ['nullable', Rule::in(['with', 'only'])],
            'filter.city' => ['nullable', 'integer'],
            'filter.venue_city' => ['nullable', 'integer'],
            'filter.venue' => ['nullable', 'integer'],
            'filter.missing_city' => ['nullable', Rule::in(['0', '1'])],
            'filter.email_verified' => ['nullable', Rule::in(['0', '1'])],
            'filter.whatsapp_verified' => ['nullable', Rule::in(['0', '1'])],
            'filter.suspended' => ['nullable', Rule::in(['0', '1'])],
        ];
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        $filters = $this->validated('filter');

        return is_array($filters)
            ? array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $filters), static fn (string $value): bool => $value !== '')
            : [];
    }

    /** Righe per pagina: 24 se non si chiede, al massimo 50 (oltre si tronca, non si rifiuta). */
    public function perPage(): int
    {
        $requested = $this->validated('per_page');

        return min(config()->integer('api.limits.max'), $requested === null ? config()->integer('api.limits.default') : (int) $requested);
    }
}
