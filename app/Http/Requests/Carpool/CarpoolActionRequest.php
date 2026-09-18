<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarpoolActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('stops'))) {
            $this->merge(['stops' => array_values(array_filter($this->input('stops'), fn ($stop) => $stop !== null && $stop !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $action = (string) $this->route('action');
        $rules = ['request_key' => ['required', 'uuid']];
        $offer = ['offer_id' => ['required', 'integer', 'min:1']];
        $request = ['request_id' => ['required', 'integer', 'min:1']];
        $fields = ['zone' => ['required', 'string', 'max:120'], 'departure_at' => ['required', 'date'],
            'capacity' => ['required', 'integer', 'min:1', 'max:8'],
            'accessibility' => ['required', Rule::enum(RideAccessibility::class)],
            'accessibility_note' => ['nullable', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:500'],
            'stops' => ['nullable', 'array', 'max:3'], 'stops.*' => ['required', 'string', 'max:120']];

        return $rules + match ($action) {
            'declare' => ['return_to' => ['nullable', 'string', 'regex:~^/passaggi/date/[1-9][0-9]*(/offri)?$~D'], 'adult' => ['accepted'], 'terms' => ['accepted'], 'version' => ['required', 'string', 'max:40']],
            'revoke-adult' => ['confirm' => ['accepted']],
            'preferences' => ['push_enabled' => ['required', 'boolean']],
            'offer' => $fields + ['occurrence_id' => ['required', 'integer', 'min:1'], 'leg' => ['required', Rule::enum(RideLeg::class)],
                'driver_declaration' => ['accepted'], 'draft' => ['sometimes', 'boolean']],
            'update' => $offer + $fields + ['revision' => ['required', 'integer', 'min:1']],
            'publish' => $offer + ['driver_declaration' => ['accepted']],
            'close', 'reopen', 'cancel' => $offer,
            'request' => $offer + ['revision' => ['required', 'integer', 'min:1'], 'seats' => ['required', 'integer', 'min:1', 'max:8'],
                'companions_adult' => [(int) $this->input('seats') > 1 ? 'accepted' : 'nullable', 'boolean'],
                'note' => ['nullable', 'string', 'max:500'], 'stop_index' => ['nullable', 'integer', 'min:0', 'max:2']],
            'reduce' => $request + ['seats' => ['required', 'integer', 'min:1', 'max:8']],
            'accept', 'decline', 'withdraw' => $request,
            default => ['action' => ['required', Rule::in([])]],
        };
    }
}
