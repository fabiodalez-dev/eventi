<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * L'esito di un controllo dei requisiti (D42, punto 4).
 *
 * La riga di confine fra `Blocking` e `Warning` è una sola domanda: *il sito,
 * dopo, risponde?* Un installer che blocca su un avviso non fa installare
 * nessuno; uno che avvisa su un errore fatale fa installare tutti male.
 */
enum RequirementStatus: string
{
    case Ok = 'ok';
    case Warning = 'avviso';
    case Blocking = 'bloccante';

    public function label(): string
    {
        return __('installer.requirements.status.'.$this->value);
    }
}
