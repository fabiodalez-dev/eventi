<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quello che serve per aprire il modulo di un evento: categorie, abitudini
 * del locale, scorciatoie della data, città, «Prima di andare» del locale.
 * Sono le stesse cose che il modulo del pannello mostra a chiunque entri nel
 * soggetto; i permessi di scrittura li controllano le rotte che salvano.
 */
final class EventFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'venue_id' => ['nullable', 'integer'],
        ];
    }
}
