<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Social;

use App\Http\Requests\Api\V1\Management\Dates\ManagedDateRequest;
use App\Models\User;

/**
 * L'anteprima JPEG della grafica di una data (W-DT-12 dell'organizzazione,
 * W-SO-09 dello studio): `EventPolicy::update`, come `social.preview` del
 * sito. Vale per il locale e per l'organizzazione.
 */
final class SocialPreviewRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('update', $this->event());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
