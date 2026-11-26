<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use App\Enums\CarpoolCaseStatus;
use App\Enums\RideStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gli elenchi delle pagine «Profili e post» e «Passaggi in auto», con la
 * convenzione degli elenchi della redazione: `page`, `per_page` (oltre il
 * massimo si tronca), `q`, `filter[status]` (stato di un caso o di un
 * passaggio). Le pagine del pannello non hanno ordinamenti: dal più recente.
 */
final class DeskListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'array'],
            'filter.status' => ['nullable', 'string', Rule::in([...array_column(CarpoolCaseStatus::cases(), 'value'), ...array_column(RideStatus::cases(), 'value')])],
        ];
    }

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

    public function search(): string
    {
        $q = $this->validated('q');

        return is_string($q) ? trim($q) : '';
    }

    public function status(): string
    {
        $status = $this->validated('filter.status');

        return is_string($status) ? $status : '';
    }
}
