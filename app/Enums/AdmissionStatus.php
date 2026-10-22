<?php

declare(strict_types=1);

namespace App\Enums;

enum AdmissionStatus: string
{
    case Valid = 'valid';
    case Waitlisted = 'waitlisted';
    case CheckedIn = 'checked_in';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /*
     * Biglietto valido mai passato dalla porta, in una serata in cui la porta
     * ha registrato ingressi. Lo scrive il comando `ticketing:no-shows` a
     * serata finita, mai la scansione: alla porta un biglietto o entra o no.
     */
    case NoShow = 'no_show';
}
