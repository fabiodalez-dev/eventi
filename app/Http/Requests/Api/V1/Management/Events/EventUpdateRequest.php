<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

/**
 * La modifica di un evento del soggetto (`EventPolicy::update`). Le regole
 * cambiano con il soggetto: il locale ha listino, scheda tecnica, link,
 * organizzatore e SEO; l'organizzazione città e locale principale.
 */
final class EventUpdateRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->subject()->isVenue()
            ? EventWriteRules::venueUpdate($this->event())
            : EventWriteRules::organizerUpdate($this->event());
    }
}
