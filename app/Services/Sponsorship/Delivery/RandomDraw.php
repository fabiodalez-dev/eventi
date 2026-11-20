<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Random\Randomizer;

/**
 * Il caso vero, quello di produzione: il generatore sicuro del sistema.
 */
final class RandomDraw implements Draw
{
    private readonly Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer;
    }

    public function fraction(): float
    {
        return $this->randomizer->nextFloat();
    }
}
