<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La vista degli elenchi sul telefono: card a tutta riga (`full`, la
 * predefinita), griglia a due colonne (`grid`), riga compatta con la
 * locandina a sinistra (`list`).
 *
 * Chi è collegato la tiene nel profilo (`users.card_layout`), così la
 * scelta fatta sul sito dal telefono vale anche nell'app e viceversa;
 * l'ospite la tiene nel browser (`resources/js/card-layout.js`).
 */
final class CardLayout
{
    public const VALUES = ['full', 'grid', 'list'];

    /** Il valore se è una delle tre viste, altrimenti null. */
    public static function choice(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::VALUES, true) ? $value : null;
    }
}
