<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un tag nuovo, condiviso fra tutti i locali, dal campo «Tag» del modulo
 * del locale. Il permesso lo controlla `CreateSharedEventTag` (creare eventi
 * per il locale, o modificare l'evento indicato).
 */
final class TagStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'event_id' => ['nullable', 'integer'],
        ];
    }
}
