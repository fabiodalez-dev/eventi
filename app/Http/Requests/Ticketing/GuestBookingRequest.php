<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use Propaganistas\LaravelPhone\Rules\Phone as PhoneRule;

/**
 * La prenotazione presa al banco: i nomi, un recapito facoltativo, una nota.
 * I nomi arrivano uno per riga da un'area di testo, perché al telefono si
 * scrive in fretta e un modulo con «aggiungi partecipante» rallenta.
 */
class GuestBookingRequest extends ManageRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'names' => ['required', 'string', 'max:3000'],
            'email' => ['nullable', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', (new PhoneRule)->country('IT')->type(['mobile', 'fixed_line'])->international()],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        $lines = preg_split('/\R/u', (string) $this->validated('names')) ?: [];

        return array_values(array_filter(array_map(trim(...), $lines), static fn (string $line): bool => $line !== ''));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['names' => __('ticketing.participants'), 'email' => __('ticketing.email'), 'phone' => __('ticketing.fields.phone'), 'note' => __('ticketing.guest.note')];
    }
}
