<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Social;

use App\Services\Social\SocialStudioDesk;
use Illuminate\Validation\Rule;

/**
 * Le letture dello studio: le serate di un periodo a card (W-SO-01), il
 * piccolo calendario del mese (W-SO-02), le serate preparabili di un giorno
 * (W-SO-03) e gli eventi fra cui scegliere per restringere lo studio.
 */
final class SocialCatalogRequest extends SocialStudioRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(SocialStudioDesk::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SocialStudioDesk::MAX_CARDS],
            'event' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): string
    {
        return trim(mb_substr($this->string('search')->toString(), 0, 100));
    }
}
