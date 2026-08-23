<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La migrazione dei salvataggi fatti da anonimo (§15.1), chiesta dal browser
 * subito dopo l'accesso.
 *
 * Il gemello dell'API è `POST /v1/me/saved/merge`: stessa azione sotto, e la
 * risposta dice quante date sono entrate — è quel numero che autorizza il
 * browser a **svuotare il `localStorage`**, mai prima.
 */
class MergeSavedRequest extends FormRequest
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
            'occurrence_ids' => ['required', 'array', 'max:'.config()->integer('account.merge_max')],
            'occurrence_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['occurrence_ids' => __('account.save.action')];
    }

    /**
     * @return array<int, int>
     */
    public function occurrenceIds(): array
    {
        $ids = $this->validated('occurrence_ids');

        return is_array($ids) ? array_map(static fn (mixed $id): int => (int) $id, $ids) : [];
    }
}
