<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * Un evento come esce dal `map()` di un driver: normalizzato, ma non ancora
 * scritto da nessuna parte (§14.2).
 *
 * **Gli istanti sono sempre in UTC.** È qui che finisce il lavoro sui fusi: il
 * driver ha già deciso se una data fluttuante andava letta nel fuso della città,
 * se portava una `Z` o se dichiarava un `TZID`, e da questo punto in avanti
 * nessuno se lo chiede più. Chi legge un `ImportedEventDto` legge istanti
 * assoluti.
 *
 * `exdates` fa eccezione ed è deliberato: `event_recurrences.exdates` viene
 * riletto da `GenerateOccurrencesAction` con il fuso della città, quindi le
 * esclusioni si scrivono come **ora locale** — una data pura `Y-m-d` esclude
 * l'intera giornata, un istante `Y-m-d H:i:s` esclude la sola occorrenza.
 */
final readonly class ImportedEventDto
{
    /**
     * @param  list<string>  $exdates  esclusioni in ora locale della città
     */
    public function __construct(
        public string $uid,
        public ?string $recurrenceId,
        public string $title,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public bool $isAllDay,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $url = null,
        public ?string $rrule = null,
        public array $exdates = [],
        public ?CarbonImmutable $until = null,
        public bool $isCancelled = false,
    ) {}

    /**
     * La chiave logica dell'evento dentro il proprio calendario: `UID`, più
     * `RECURRENCE-ID` quando la voce è l'eccezione di una serie.
     *
     * Le due forme devono restare distinte: in un ICS la data spostata di una
     * serie porta lo **stesso** `UID` della serie, e trattarle come una cosa
     * sola farebbe sovrascrivere la serie con la propria eccezione a ogni
     * esecuzione.
     */
    public function key(): string
    {
        return $this->recurrenceId === null
            ? $this->uid
            : $this->uid.'#'.$this->recurrenceId;
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null && $this->rrule !== '';
    }
}
