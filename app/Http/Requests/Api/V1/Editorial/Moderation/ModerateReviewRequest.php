<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use App\Services\Editorial\EditorialOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Approva» o «Rifiuta» una recensione, come i due riquadri del pannello: la
 * revisione letta all'apertura (se un altro ha deciso nel frattempo, 422 su
 * `revision`) e la nota, da 5 a 1000 caratteri, obbligatoria solo per
 * rifiutare. `CatalogReviews::moderate` ricontrolla tutto prima di scrivere.
 */
final class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:approved,rejected'],
            'revision' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'required_if:status,rejected', 'string', 'min:'.EditorialOptions::REASON_MIN, 'max:'.EditorialOptions::REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['note' => __('reviews.note')];
    }
}
