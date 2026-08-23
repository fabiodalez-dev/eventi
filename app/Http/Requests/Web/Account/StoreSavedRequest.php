<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Il cuore del sito (§15.1). Accetta una data sola o un elenco: la scheda di
 * un evento con più serate offre anche «salva tutte le date» (§15.3), e sono
 * la stessa richiesta con un elemento invece di dieci.
 */
class StoreSavedRequest extends FormRequest
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
            'occurrence_id' => ['required_without:occurrence_ids', 'integer', 'min:1'],
            'occurrence_ids' => ['required_without:occurrence_id', 'array', 'max:'.config()->integer('account.merge_max')],
            'occurrence_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'occurrence_id' => __('account.save.action'),
            'occurrence_ids' => __('account.save.all_dates'),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function occurrenceIds(): array
    {
        $single = $this->validated('occurrence_id');
        $many = $this->validated('occurrence_ids');

        if (is_array($many)) {
            return array_map(static fn (mixed $id): int => (int) $id, $many);
        }

        return $single === null ? [] : [(int) $single];
    }
}
