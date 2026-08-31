<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A chi arrivano gli allarmi di esercizio (§16).
 *
 * Esiste perché la risposta deve essere **la stessa** per il backup e per i
 * controlli di stato: due liste separate si allineano il primo giorno e
 * divergono al primo cambio di indirizzo, e la seconda se ne accorge nessuno
 * finché non serve.
 *
 * La forma è un array anche con un solo destinatario: `spatie/laravel-backup`
 * valida ogni voce come indirizzo e rifiuterebbe la stringa vuota, mentre un
 * array vuoto è una destinazione assente — e un canale senza destinazione
 * Laravel lo salta. È così che «nessun indirizzo configurato» significa
 * «nessun invio» invece di «errore all'avvio».
 */
final class OpsAlerts
{
    /**
     * L'indirizzo — o gli indirizzi separati da virgola — arriva dai file di
     * configurazione, che sono l'unico posto da cui si legge l'ambiente: con la
     * configurazione in cache `env()` risponde null ovunque altro.
     *
     * @return array<int, string>
     */
    public static function recipients(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $addresses = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
