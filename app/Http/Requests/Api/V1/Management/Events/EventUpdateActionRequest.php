<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

/**
 * Un'azione che cambia l'evento senza un modulo (pubblica, invia alla
 * redazione, anteprima, annulla programmazione): `EventPolicy::update`, come
 * i pulsanti dei pannelli.
 */
final class EventUpdateActionRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
