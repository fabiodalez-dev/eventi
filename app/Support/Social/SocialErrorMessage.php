<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Database\RecordsNotFoundException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Quale errore della pubblicazione sui social si può mostrare a chi
 * pubblica.
 *
 * Le regole dei social (`SocialPublisher`, `TelegramPublisher`,
 * `EditorialPublishing`) dicono «no» con una `RuntimeException` e una frase
 * per la persona: collegamento non verificato, didascalia troppo lunga,
 * evento cambiato. Ma anche un errore del database (`QueryException` è una
 * `PDOException`, cioè una `RuntimeException`) e un record che non c'è
 * (`ModelNotFoundException`) lo sono, e il loro messaggio porta la query,
 * i nomi delle tabelle e i valori: non va né in una risposta dell'API né
 * nella colonna `error` di una pubblicazione, che lo storico mostra.
 */
final class SocialErrorMessage
{
    /** La frase della regola, oppure `null` per un errore interno, che va al gestore degli errori. */
    public static function forPeople(Throwable $e): ?string
    {
        if (! $e instanceof RuntimeException || $e instanceof PDOException || $e instanceof RecordsNotFoundException) {
            return null;
        }
        $message = trim($e->getMessage());

        return $message === '' || self::looksInternal($message) ? null : $message;
    }

    /** Un errore già salvato in una pubblicazione: quello che sembra del database diventa il messaggio generico. */
    public static function stored(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        return self::looksInternal($error) ? __('social.internal_error') : $error;
    }

    private static function looksInternal(string $message): bool
    {
        return preg_match('/SQLSTATE|\bSQL:|No query results for model|Illuminate\\\\|App\\\\Models\\\\/i', $message) === 1;
    }
}
