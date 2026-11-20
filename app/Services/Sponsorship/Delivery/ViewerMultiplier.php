<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;

/**
 * Il punto di estensione del moltiplicatore per chi guarda (`f_i` del piano).
 *
 * Si moltiplica per l'affinità (`a_i`, che c'è già) dentro
 * `P(i) = b·m ÷ (Σ b·m + riserva)`. Nel secondo rilascio (P7) è il tetto di
 * frequenza per chi è collegato: 1 ; 0,75 ; 0,5 ; 0,25 ; 0. Nel primo vale 1
 * per tutti, e la realizzazione neutra non restituisce niente.
 *
 * Per gli anonimi `$user` è `null`: la loro pagina è condivisa, e un
 * moltiplicatore personale finito in cache verrebbe servito a tutti.
 */
#[Bind(NeutralViewerMultiplier::class)]
interface ViewerMultiplier
{
    /**
     * Un moltiplicatore per unità (chiave `g:12` o `s:45`). Una chiave che
     * manca vale 1; zero toglie l'unità dall'estrazione.
     *
     * @return array<string, float>
     */
    public function multipliers(DeliveryPlan $plan, ?User $user, CarbonImmutable $now): array;
}
