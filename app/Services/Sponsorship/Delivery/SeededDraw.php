<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Il caso con un seme: la stessa sequenza a ogni esecuzione.
 *
 * È lo stesso generatore della simulazione del piano
 * (`03-simulazione.php`: `Xoshiro256StarStar` e `nextFloat()`), quindi i numeri
 * che il piano riporta si ritrovano identici nei test.
 */
final class SeededDraw implements Draw
{
    private readonly Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function fraction(): float
    {
        return $this->randomizer->nextFloat();
    }
}
