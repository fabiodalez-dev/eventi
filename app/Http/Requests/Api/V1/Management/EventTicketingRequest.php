<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\Event;
use App\Services\Management\ManagedContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il riquadro «Ticketing inCittà» di un evento del locale
 * (`GET V/events/{event}/ticketing`).
 *
 * L'evento si cerca solo fra quelli del locale (404 altrimenti), poi decide
 * `EventPolicy::update`, come la pagina «Modifica evento» che mostra il
 * riquadro.
 */
final class EventTicketingRequest extends FormRequest
{
    private ?Event $event = null;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->event()) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function event(): Event
    {
        return $this->event ??= ManagedContent::current()->event((int) $this->route('event'));
    }
}
