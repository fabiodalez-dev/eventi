<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * `GET /v1/me/saved` (§15.8).
 *
 * `upcoming=1` è il valore predefinito perché il salvataggio serve ad andarci:
 * l'agenda comincia da adesso. Con `upcoming=0` arriva anche l'archivio, e in
 * quel caso l'ordine si ribalta — dal più recente — perché un archivio letto
 * dalla serata più lontana sarebbe una lista da scorrere fino in fondo.
 */
class SavedQueryRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'upcoming' => ['nullable', 'boolean'],
        ];
    }

    public function onlyUpcoming(): bool
    {
        $value = $this->validated('upcoming');

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
