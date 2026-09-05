<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use Illuminate\Foundation\Http\FormRequest;

class ManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'configure' => [
                'booking_enabled' => ['required', 'boolean'],
                'booking_capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'booking_limit' => ['required', 'integer', 'min:1', 'max:20'],
                'booking_waitlist' => ['required', 'boolean'],
                'booking_opens_at' => ['nullable', 'date'],
                'booking_closes_at' => ['nullable', 'date', ...($this->filled('booking_opens_at') ? ['after:booking_opens_at'] : [])],
                'cancellation_closes_at' => ['nullable', 'date'],
                'booking_instructions' => ['nullable', 'string', 'max:3000'],
                'booking_fields' => ['sometimes', 'array:phone,address,city,postal_code,country'],
                'booking_fields.*' => ['required', 'string', 'in:hidden,optional,required'],
            ],
            default => ['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']],
        };
    }
}
