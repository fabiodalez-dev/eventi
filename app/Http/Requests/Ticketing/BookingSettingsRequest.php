<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use App\Models\Booking;
use App\Models\EventOccurrence;

/**
 * Le impostazioni delle prenotazioni di una data dall'app
 * (`PATCH /api/v1/management/dates/{data}/settings`). Le regole sono quelle
 * del modulo del sito (`ManageRequest::settingsRules()`); il permesso è
 * `BookingPolicy::manage`, come la pagina `/gestione-biglietti/{data}`: il
 * collaboratore del locale e il personale d'ingresso ricevono 403.
 */
final class BookingSettingsRequest extends ManageRequest
{
    public function authorize(): bool
    {
        $date = $this->route('occurrence');

        return $date instanceof EventOccurrence && $this->user()?->can('manage', [Booking::class, $date]) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->settingsRules();
    }
}
