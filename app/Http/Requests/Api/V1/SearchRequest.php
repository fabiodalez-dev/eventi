<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * `GET /v1/search` (§13.1): eventi, locali e tag che somigliano al testo.
 *
 * Due caratteri sono il minimo: sotto quella soglia la risposta è l'intero
 * catalogo travestito da risultato.
 */
final class SearchRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }

    protected function defaultLimit(): int
    {
        return config()->integer('api.limits.search');
    }
}
