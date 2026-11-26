<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un testo delle email: il testo (vuoto = torna all'originale, come nel
 * pannello) e la revisione letta all'apertura.
 */
final class UpdateNotificationTextRequest extends FormRequest
{
    /**
     * Il campo del pannello non ha un tetto; la colonna sì (`text`). Il
     * tetto qui trasforma in un 422 quello che altrimenti sarebbe un errore
     * del database.
     */
    public const VALUE_MAX = 60000;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'value' => ['present', 'nullable', 'string', 'max:'.self::VALUE_MAX],
            'revision' => ['required', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['value' => __('editorial-settings.texts.value')];
    }
}
