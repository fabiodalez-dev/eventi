<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Gli errori previsti dello studio delle grafiche social, quelli che chi
 * prepara un pacchetto deve leggere: un testo che non sta nella grafica, lo
 * spazio su disco, il tetto giornaliero del locale.
 *
 * Esiste perché prima la pagina e l'API trasformavano **ogni**
 * `RuntimeException` in un messaggio per chi usa lo studio, e
 * `RuntimeException` è anche la madre di `QueryException`: un errore del
 * database arrivava al client con l'SQL dentro. Ora solo questa classe
 * diventa un messaggio (422 nell'API, l'errore sotto il pulsante nel
 * pannello); tutto il resto va al gestore degli errori, che lo registra e
 * risponde con un errore generico.
 *
 * Il messaggio è già in italiano e già pronto da mostrare.
 */
final class SocialStudioException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $dailyLimit = false)
    {
        parent::__construct($message);
    }

    public static function chooseEvents(): self
    {
        return new self(__('social.choose_events'));
    }

    public static function textOverflow(): self
    {
        return new self(__('social.text_overflow'));
    }

    public static function storageError(): self
    {
        return new self(__('social.storage_error'));
    }

    /** Il locale ha già preparato il massimo dei pacchetti nelle ultime 24 ore. */
    public static function dailyLimit(int $limit): self
    {
        return new self(__('social.daily_limit', ['count' => $limit]), true);
    }
}
