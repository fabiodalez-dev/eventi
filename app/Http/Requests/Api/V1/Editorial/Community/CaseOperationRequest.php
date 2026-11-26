<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use Illuminate\Validation\Rule;

/**
 * Le operazioni su un caso con la revisione letta, come i pulsanti del
 * pannello: assegna, nota, risolvi, riapri (`manage`), preserva o rilascia
 * le evidenze (`hold`). Una revisione vecchia è un 409 del servizio: qualcuno
 * ha cambiato il caso nel frattempo.
 */
final class CaseOperationRequest extends ReasonRequest
{
    public const MANAGE = ['assign', 'note', 'resolve', 'reopen'];

    public const RETENTION = ['hold', 'release'];

    protected function extraRules(): array
    {
        return [
            'op' => ['required', 'string', Rule::in($this->routeIs('api.v1.editorial.carpool.cases.hold') ? self::RETENTION : self::MANAGE)],
            'revision' => ['required', 'integer', 'min:0'],
        ];
    }
}
