<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Stessa sera in zona» e chiusura straordinaria per un orario del modulo.
 * Sono avvisi: li chiede chiunque stia compilando un evento del soggetto.
 */
final class EventChecksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
            'event_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'venue_id' => ['nullable', 'integer'],
            'custom_location' => ['nullable', 'array:name,address,lat,lng'],
            'custom_location.address' => ['nullable', 'string', 'max:255'],
            'custom_location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'custom_location.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
