<?php

declare(strict_types=1);

namespace App\Actions\Ticketing;

use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;

/**
 * Salva le impostazioni delle prenotazioni di una data: le chiamano la
 * scheda della data sul sito (`/gestione-biglietti/{data}/impostazioni`),
 * «Configura ticketing» nella modifica dell'evento del pannello del locale e
 * l'app (`PATCH /api/v1/management/dates/{data}/settings`). Una regola sola.
 *
 * È una sostituzione, come il modulo del sito: una delle tre ore che manca
 * torna vuota.
 *
 * Apertura, chiusura e termine di annullamento arrivano come li scrive chi
 * gestisce: un'ora della città dell'evento (`2026-12-05 21:00`) oppure un
 * istante con il suo scarto (`2026-12-05T21:00:00+01:00`, quello che manda
 * l'app). Vuoto vuol dire «all'inizio dell'evento». Le regole vere (locale
 * abilitato, capienza non sotto i posti occupati, domande) restano in
 * `TicketingService::configure()`.
 */
final class ConfigureBookings
{
    public const TIMES = ['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'];

    public function __construct(private readonly TicketingService $service) {}

    /** @param array<string, mixed> $settings i campi validati di `ManageRequest::settingsRules()` */
    public function __invoke(EventOccurrence $date, array $settings, User $actor): void
    {
        $timezone = $date->event?->city->timezone ?? config()->string('app.timezone');
        foreach (self::TIMES as $field) {
            $settings[$field] = empty($settings[$field]) ? null : CarbonImmutable::parse((string) $settings[$field], $timezone)->utc();
        }

        $this->service->configure($date, $settings, $actor);
    }
}
