<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Trappola per i robot dei moduli pubblici (§14.7).
 *
 * Un campo che una persona non vede e che non incontra col tabulatore, ma che
 * un compilatore automatico riempie sempre. Se arriva pieno, la richiesta non
 * è di una persona e la validazione la respinge.
 *
 * Non sostituisce il limite di frequenza: lo affianca. Un robot scritto apposta
 * per questo sito lo aggira in cinque minuti, uno generico no — e sono quelli
 * generici a fare quasi tutto il rumore.
 */
final class Honeypot
{
    /**
     * Il nome somiglia a un campo vero: un robot che saltasse i campi chiamati
     * "honeypot" cascherebbe comunque qui.
     */
    public const FIELD = 'website_url';

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [self::FIELD => ['prohibited']];
    }
}
