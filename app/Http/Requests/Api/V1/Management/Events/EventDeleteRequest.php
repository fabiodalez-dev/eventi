<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

/** «Elimina» dal menu della modifica (`EventPolicy::delete`). */
final class EventDeleteRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'delete';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
