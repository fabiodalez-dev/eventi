<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/me/saved/merge` (§15.1): la migrazione dei salvataggi di chi ha
 * usato il sito senza registrarsi.
 *
 * La lista arriva dal `localStorage` e può contenere di tutto — date
 * cancellate, eventi mai pubblicati, serate di sei mesi fa. Nessuno di quei
 * casi è un errore da segnalare: si ignorano, e la risposta dice che cosa è
 * entrato davvero. Il tetto serve a un solo scopo: distinguere una migrazione
 * da un carico.
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
     * @return array<int, int>
     */
    public function occurrenceIds(): array
    {
        $ids = $this->validated('occurrence_ids');

        return is_array($ids) ? array_map(static fn (mixed $id): int => (int) $id, $ids) : [];
    }
}
