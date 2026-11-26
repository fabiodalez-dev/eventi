<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Services\Editorial\EditorialOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Cambia nome utente» (R-US-09): il nome nuovo (le regole vere del nome le
 * applica `CommunityModeration::renameHandle`, come per il pannello) e il
 * motivo, da 5 a 1000 caratteri. Chi può farlo lo decide l'azione.
 */
final class RenameHandleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:'.EditorialOptions::REASON_MIN, 'max:'.EditorialOptions::REASON_MAX],
        ];
    }
}
