<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

/** La scheda di un evento del soggetto (`EventPolicy::view`). */
final class EventViewRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'view';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
