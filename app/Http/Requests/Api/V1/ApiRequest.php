<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\ApiInclude;
use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

/**
 * Ciò che ogni richiesta dell'API ha in comune: quanti elementi, da quale
 * cursore, con quali inclusioni.
 *
 * **L'API valida in modo severo, il sito no.** Un link storpiato aperto da una
 * persona deve rendere una pagina sensata (`EventFilterRequest` scarta i
 * parametri che non riconosce); una richiesta storpiata fatta da un programma
 * deve invece dirlo, perché è un difetto del programma e va corretto lì.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'max:120'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'between:1,'.$this->maxLimit()],
            'include' => ['nullable', 'string', 'max:120', $this->includePattern()],
        ];
    }

    /**
     * Un elenco separato da virgole di sole inclusioni conosciute.
     */
    private function includePattern(): string
    {
        $values = implode('|', ApiInclude::values());

        return sprintf('regex:/^(%s)(,(%s))*$/', $values, $values);
    }

    /**
     * Un cursore illeggibile è un 400 e non un 422: non è un dato sbagliato
     * dell'utente, è una stringa che questo server ha emesso e che è stata
     * alterata per strada. Laravel, da solo, lo tratterebbe come "prima
     * pagina", e il client scorrerebbe per sempre lo stesso inizio di lista.
     */
    protected function prepareForValidation(): void
    {
        $cursor = $this->query('cursor');

        if (is_string($cursor) && $cursor !== '' && Cursor::fromEncoded($cursor) === null) {
            throw new ApiException(ApiErrorCode::InvalidCursor);
        }
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : $this->defaultLimit();
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * `include=venue,tags,lineup` (§13.2). I valori sconosciuti fanno fallire
     * la validazione: chiedere `include=venu` e ricevere una risposta senza
     * locale, senza alcun segnale, è il modo migliore per perderci un'ora.
     *
     * @return list<ApiInclude>
     */
    public function includes(): array
    {
        $raw = $this->validated('include');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $includes = [];

        foreach (explode(',', $raw) as $value) {
            $include = ApiInclude::tryFrom(trim($value));

            if ($include !== null && ! in_array($include, $includes, true)) {
                $includes[] = $include;
            }
        }

        return $includes;
    }

    protected function defaultLimit(): int
    {
        return config()->integer('api.limits.default');
    }

    protected function maxLimit(): int
    {
        return config()->integer('api.limits.max');
    }
}
