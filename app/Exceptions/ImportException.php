<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Il guasto che ferma un'intera esecuzione di import: il calendario non si
 * scarica, non si legge, o la sorgente non ha un driver.
 *
 * Non è l'errore di una singola voce — quello finisce nel resoconto e non
 * interrompe niente. Questo invece **si rilancia**, perché è la sola forma di
 * guasto che ha senso riprovare: una rete che non risponde adesso può
 * rispondere fra un minuto, e un file troncato a metà scaricamento arriva
 * intero al tentativo dopo.
 *
 * Il messaggio è già in italiano e già pronto per `import_sources.last_error`:
 * è il testo che un redattore legge nel pannello, quindi non contiene nomi di
 * classi né tracce di stack.
 */
final class ImportException extends RuntimeException
{
    public static function noDriver(string $type): self
    {
        return new self(__('import.errors.no_driver', ['type' => $type]));
    }

    public static function noUrl(): self
    {
        return new self(__('import.errors.no_url'));
    }

    public static function invalidUrl(): self
    {
        return new self(__('import.errors.invalid_url'));
    }

    public static function unsupportedScheme(): self
    {
        return new self(__('import.errors.unsupported_scheme'));
    }

    /**
     * L'indirizzo punta alla rete in cui vive il server (SSRF). Il messaggio
     * nomina l'indirizzo che ha fatto scattare il rifiuto — che può essere
     * quello scritto o quello in cui il nome si è risolto — perché senza
     * quel dato chi ha configurato la sorgente non capisce che cosa correggere.
     */
    public static function privateAddress(string $host): self
    {
        return new self(__('import.errors.private_address', ['host' => $host]));
    }

    public static function unreachable(string $url, string $reason): self
    {
        return new self(__('import.errors.unreachable', ['url' => $url, 'reason' => $reason]));
    }

    public static function httpStatus(int $status): self
    {
        return new self(__('import.errors.http_status', ['status' => $status]));
    }

    public static function tooLarge(int $bytes): self
    {
        return new self(__('import.errors.too_large', ['bytes' => $bytes]));
    }

    public static function unreadable(string $reason): self
    {
        return new self(__('import.errors.unreadable', ['reason' => $reason]));
    }

    public static function notACalendar(): self
    {
        return new self(__('import.errors.not_a_calendar'));
    }

    public static function noCategory(): self
    {
        return new self(__('import.errors.no_category'));
    }
}
