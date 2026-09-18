<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Esito di un invio a Kapso. Tre valori e non un booleano: un timeout non
 * dice se il messaggio è partito, e trattarlo come un rifiuto riaprirebbe i
 * limiti a chi ha già ricevuto il codice.
 */
enum KapsoOutcome: string
{
    /** Kapso ha accettato il messaggio e restituito un id. */
    case Sent = 'sent';

    /** Niente è partito: servizio spento, risposta d'errore o host irraggiungibile. */
    case Rejected = 'rejected';

    /** La richiesta è uscita ma la risposta no: il codice potrebbe essere arrivato. */
    case Uncertain = 'uncertain';
}
