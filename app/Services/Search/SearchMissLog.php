<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\City;
use App\Models\SearchMiss;
use App\Support\IpBucket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Registra una ricerca senza risultati.
 *
 * Il termine si normalizza (minuscolo, spazi compressi, troncato) così che
 * «Jazz», «jazz » e «JAZZ» siano la stessa domanda. Una riga per città e per
 * termine, con il conteggio che cresce: quello che la redazione guarda non è
 * «chi» ma «quante volte». Termini troppo corti o troppo lunghi non si
 * registrano: non dicono niente, o sono un incollaggio per sbaglio.
 *
 * Le ricerche sono anonime e ogni termine nuovo è una riga nuova: per questo
 * chi cerca da un indirizzo registra al massimo `PER_ACTOR_HOURLY` mancanze
 * l'ora. Oltre, la ricerca risponde lo stesso e il registro non si muove —
 * uno script non può gonfiare la tabella né riempire di rumore (o di parole
 * scelte da lui) l'elenco che legge la redazione. L'indirizzo serve solo al
 * contatore in cache: nella tabella resta, come prima, nessuno.
 */
final class SearchMissLog
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 120;

    public const PER_ACTOR_HOURLY = 30;

    /**
     * `$actor` è l'indirizzo di chi cerca; senza, come da riga di comando,
     * nessun tetto. Con IPv6 si conta il /64 (`IpBucket`): dentro il proprio
     * prefisso un host cambia indirizzo a ogni richiesta.
     */
    public function record(City $city, string $term, ?string $actor = null): void
    {
        $term = Str::of($term)->squish()->lower()->limit(self::MAX_LENGTH, '')->toString();
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return;
        }
        if ($actor !== null && ! RateLimiter::attempt('search-miss:'.IpBucket::key($actor), self::PER_ACTOR_HOURLY, static fn (): bool => true, 3600)) {
            return;
        }
        $now = now();
        DB::table('search_misses')->upsert(
            [['city_id' => $city->getKey(), 'term' => $term, 'count' => 1, 'first_searched_at' => $now, 'last_searched_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['city_id', 'term'],
            ['count' => DB::raw('search_misses.count + 1'), 'last_searched_at' => $now, 'updated_at' => $now],
        );
    }

    /** Quante volte una città ha cercato invano questo termine (per i test e i riquadri). */
    public function count(City $city, string $term): int
    {
        return (int) SearchMiss::query()->where('city_id', $city->getKey())
            ->where('term', Str::of($term)->squish()->lower()->toString())->value('count');
    }
}
