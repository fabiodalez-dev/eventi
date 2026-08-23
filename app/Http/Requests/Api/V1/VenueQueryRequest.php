<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\VenueType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * `GET /v1/venues` (§13.1).
 *
 * L'elenco dei locali non è una lista temporale: si sfoglia per nome, e il
 * cursore corre su nome e identificativo. Resta a cursore come tutto il resto
 * (§13.6), perché un client che sa leggere una lista le sa leggere tutte.
 */
final class VenueQueryRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(VenueType::values())],
            'updated_since' => ['nullable', 'date'],
        ];
    }

    public function term(): string
    {
        $value = $this->validated('q');

        return is_string($value) ? trim($value) : '';
    }

    public function municipality(): ?string
    {
        $value = $this->validated('municipality');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function type(): ?VenueType
    {
        $value = $this->validated('type');

        return is_string($value) ? VenueType::tryFrom($value) : null;
    }

    public function updatedSince(): ?CarbonImmutable
    {
        $value = $this->validated('updated_since');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
