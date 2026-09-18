<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\KapsoOutcome;
use App\Models\WhatsappChallenge;

/**
 * Una richiesta di codice andata a buon fine, o forse: l'esito incerto torna
 * al controller perché l'utente sappia che il messaggio potrebbe non arrivare.
 * Un rifiuto non arriva mai qui, diventa un errore di validazione.
 */
final readonly class WhatsappSendResult
{
    public function __construct(
        public WhatsappChallenge $challenge,
        public KapsoOutcome $outcome,
    ) {}
}
