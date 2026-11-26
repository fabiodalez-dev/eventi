<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Community;

use Illuminate\Validation\ValidationException;

/**
 * Le azioni con un sì o un no e il motivo: mettere o togliere un profilo
 * dall'evidenza (`enabled`), oscurare o riaprire la chat di un caso
 * (`freeze`), nascondere o ripristinare una recensione di passaggio
 * (`visible`). Il controller dice quale chiave legge.
 */
final class ToggleRequest extends ReasonRequest
{
    protected function extraRules(): array
    {
        return [
            'enabled' => ['sometimes', 'required', 'boolean'],
            'freeze' => ['sometimes', 'required', 'boolean'],
            'visible' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** Il valore della chiave; 422 sotto la chiave se manca. */
    public function flag(string $key): bool
    {
        if (! $this->has($key)) {
            throw ValidationException::withMessages([$key => __('validation.required', ['attribute' => $key])]);
        }

        return $this->boolean($key);
    }
}
