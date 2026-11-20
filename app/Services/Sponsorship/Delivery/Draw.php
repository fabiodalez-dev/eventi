<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Illuminate\Container\Attributes\Bind;

/**
 * Il caso, come dipendenza.
 *
 * Ogni scelta della consegna passa da qui invece di chiamare `random_int()` o
 * `mt_rand()` direttamente: così nei test il caso si fissa con un seme
 * (`SeededDraw`) e i numeri escono uguali a ogni esecuzione, e dove serve la
 * stessa scelta per tutti (l'apertura della home dentro la casella della
 * cache) il numero viene da un hash (`HashDraw`).
 *
 * Senza il provider delle sponsorizzazioni il contenitore usa comunque il caso
 * vero: è l'attributo qui sotto.
 */
#[Bind(RandomDraw::class)]
interface Draw
{
    /** Un numero in [0, 1): zero compreso, uno escluso. */
    public function fraction(): float;
}
