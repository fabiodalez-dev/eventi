<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use App\Services\Editorial\CarpoolDesk;
use Illuminate\Validation\Rule;

/** Sospendi o ripristina una persona in un ambito: solo social, solo passaggi, tutta la community. */
final class RestrictUserRequest extends ReasonRequest
{
    protected function extraRules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(array_keys(CarpoolDesk::RESTRICTION_SCOPES))],
        ];
    }
}
