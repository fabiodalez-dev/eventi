<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\Event;
use App\Services\Management\ManagedContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La locandina di un evento del soggetto (`POST` e `DELETE …/events/{event}/poster`).
 *
 * L'evento si cerca solo fra quelli del soggetto (404 altrimenti), poi decide
 * `EventPolicy::update`, come la pagina «Modifica evento» dei pannelli.
 */
final class EventPosterRequest extends FormRequest
{
    private ?Event $event = null;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->event()) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('post') ? ImageRules::rules() : [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ImageRules::attributes();
    }

    public function event(): Event
    {
        return $this->event ??= ManagedContent::current()->event((int) $this->route('event'));
    }
}
