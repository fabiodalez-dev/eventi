<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Import\ImportUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * L'indirizzo di una sorgente, verificato **mentre lo si scrive**.
 *
 * È la stessa difesa che `IcsImportDriver` applica prima di scaricare
 * (`ImportUrlGuard`), non una seconda: il modulo la chiama per poterlo dire
 * subito, in italiano e accanto al campo, invece di lasciarlo scoprire alla
 * prima esecuzione — o peggio, di lasciare in tabella una sorgente che punta
 * alla rete interna del server e che nessuno ha motivo di guardare.
 *
 * Salvare non è mai l'ultima parola: la verifica al momento dello scaricamento
 * resta, perché un nome può cambiare indirizzo dopo essere stato salvato.
 */
class SafeImportUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $problem = app(ImportUrlGuard::class)->reject($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
