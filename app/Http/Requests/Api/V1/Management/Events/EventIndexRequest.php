<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Enums\EventStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'elenco eventi del soggetto: le quattro schede del pannello, il filtro di
 * stato, la ricerca per titolo. Lo vede chiunque entri nel soggetto (la
 * porta l'ha già controllata `ResolveManagementSubject`).
 */
final class EventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['all', 'upcoming', 'past', 'drafts'])],
            'status' => ['nullable', Rule::enum(EventStatus::class)],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
