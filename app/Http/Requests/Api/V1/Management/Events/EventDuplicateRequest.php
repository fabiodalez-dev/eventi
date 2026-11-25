<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Models\Event;

/**
 * «Duplica»: chi può creare eventi per il locale dell'evento
 * (`EventPolicy::create`), come l'azione del pannello.
 */
final class EventDuplicateRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'view';
    }

    public function authorize(): bool
    {
        $event = $this->event();

        return $event->venue !== null && $this->user()?->can('create', [Event::class, $event->venue]) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
