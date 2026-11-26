<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Social;

/**
 * «Programma» e «Usa la nuova data e ora» (R-SO-03): `scheduled_at` come il
 * campo data e ora della pagina, `Y-m-d\TH:i` nel fuso della città.
 */
final class SocialScheduleRequest extends EditorialSocialRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['scheduled_at' => ['required', 'date_format:Y-m-d\TH:i']];
    }
}
