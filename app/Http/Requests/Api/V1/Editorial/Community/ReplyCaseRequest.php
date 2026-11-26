<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use App\Services\Editorial\EditorialOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Invia risposta» del pannello: il destinatario (una delle persone del
 * caso, lo ricontrolla il servizio) e il messaggio che riceve, da 5 a 1000
 * caratteri, con la revisione letta.
 */
final class ReplyCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:'.EditorialOptions::REASON_MIN, 'max:'.EditorialOptions::REASON_MAX],
            'revision' => ['required', 'integer', 'min:0'],
        ];
    }
}
