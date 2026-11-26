<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Social;

use App\Services\Social\SocialStudioDesk;
use Illuminate\Validation\Rule;

/**
 * Le letture dello studio della redazione: le serate di un periodo a card,
 * il piccolo calendario del mese, le serate preparabili di un giorno. Gli
 * stessi parametri dello studio del locale (P10).
 */
final class SocialCatalogRequest extends EditorialSocialRequest
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SocialStudioDesk::MAX_CARDS],
            'event' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): string
    {
        return trim(mb_substr($this->string('search')->toString(), 0, 100));
    }
}
