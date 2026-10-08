<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * Filament ricava la voce di menu dal nome plurale del modello passandolo
 * per `Str::headline()`, che in inglese dà «Event Submissions» e in italiano
 * dà «Proposte Eventi», «Richieste Di Iscrizione», «Prima Di Andare»: ogni
 * parola in maiuscolo, preposizioni comprese. Qui la voce resta com'è stata
 * scritta, con la sola iniziale maiuscola.
 */
trait HasSentenceCaseNavigationLabel
{
    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? Str::ucfirst(static::getPluralModelLabel());
    }
}
