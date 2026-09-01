<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * «Una volta in quest'ora, al primo giro utile».
 *
 * **Perché non basta `->hourly()`.** Quello significa «al minuto zero», e
 * basta che il cron slitti di un minuto perché il comando salti l'ora intera.
 * Non è un'ipotesi: su questo server, in sei ore, sono andati persi i giri
 * delle 12:00 e delle 16:00 — uno mentre un rilascio teneva occupata la
 * macchina, l'altro senza un motivo visibile. Su una shared hosting la
 * macchina è di tutti, e il minuto esatto non è garantito a nessuno.
 *
 * Il comando allora si presenta ogni cinque minuti e passa solo la prima volta
 * in ciascuna ora. `Cache::add` scrive soltanto se la chiave non c'è, ed è una
 * sola operazione atomica: due esecuzioni simultanee non possono passare
 * entrambe. La chiave porta l'ora nel nome e scade da sé.
 *
 * Il risultato è la stessa cadenza di prima con un'ora di tolleranza sul
 * minuto: se le 12:00 si perdono, il comando parte alle 12:05.
 */
final class OncePerHour
{
    /**
     * La condizione da passare a `->when()` nello scheduler.
     */
    public static function for(string $command): callable
    {
        return static fn (): bool => Cache::add(
            'schedule:'.$command.':'.now()->format('Y-m-d-H'),
            true,
            now()->addHour(),
        );
    }
}
