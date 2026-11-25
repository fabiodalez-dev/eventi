<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use Illuminate\Foundation\Http\FormRequest;

/** La ricerca di un tag o di un locale per i campi a scelta del modulo. */
final class LookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'ids' => ['nullable', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ];
    }
}
