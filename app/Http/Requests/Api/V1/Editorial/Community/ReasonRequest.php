<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use App\Services\Editorial\EditorialOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le azioni di community e passaggi chiedono tutte un motivo, come le
 * finestre del pannello: da 5 a 1000 caratteri. Il servizio lo ricontrolla
 * (`CommunityModeration`, `CommunitySafety`, `RideReviews`); qui l'errore
 * arriva sotto il campo, con il nome del campo.
 *
 * Chi può agire lo decide il controller, con lo stesso permesso del pannello.
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.EditorialOptions::REASON_MIN, 'max:'.EditorialOptions::REASON_MAX],
            ...$this->extraRules(),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function extraRules(): array
    {
        return [];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
