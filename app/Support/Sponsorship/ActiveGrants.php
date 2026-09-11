<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

use App\Models\SponsorshipGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * L'impronta delle concessioni attive di una città: quali campagne regalate o
 * pagate stanno correndo adesso.
 *
 * Serve alla chiave della full-page cache — due visitatori con concessioni
 * diverse in corso non possono ricevere la stessa copia della pagina — e per
 * quello veniva calcolata **a ogni richiesta**, comprese quelle che poi la
 * pagina la trovavano già pronta.
 *
 * Misurato sul progetto vero, in locale con MariaDB sulla macchina stessa:
 *
 * ```
 * query delle concessioni : 1,59 ms/richiesta
 * due hash_file           : 0,06 ms/richiesta
 * tre letture di cache    : 0,16 ms/richiesta
 * ```
 *
 * Il 90% del costo di comporre la chiave stava in quella riga, e lo pagava
 * **il percorso più caldo del sito**: la strada che esiste per non fare
 * lavoro faceva una `whereHas` — cioè una sottointerrogazione correlata —
 * prima di andare a vedere se la pagina era già in cache. Su hosting condiviso,
 * dove la CPU è contesa e il database non sta sulla stessa macchina, quel
 * numero peggiora.
 *
 * ## Perché un minuto, e perché va bene
 *
 * Il valore si tiene per la stessa durata della pagina che contribuisce a
 * indicizzare (`page_cache.ttl_minutes`). Il ritardo con cui una finestra
 * temporale si fa notare passa quindi da «al più il TTL» a «al più due volte
 * il TTL»: su concessioni che durano giorni o settimane non è una differenza
 * che qualcuno possa osservare.
 *
 * La modifica **fatta a mano** invece si vede subito: `dimentica()` la chiama
 * il modello a ogni salvataggio e a ogni cancellazione. È la distinzione che
 * conta — il tempo che passa può aspettare un minuto, una persona che ha
 * appena premuto «salva» no.
 */
final class ActiveGrants
{
    /**
     * Gli identificativi delle concessioni attive, in forma stabile e
     * confrontabile.
     */
    public static function fingerprint(int $cityId): string
    {
        return Cache::remember(
            self::key($cityId),
            now()->addMinutes(config()->integer('page_cache.ttl_minutes')),
            fn (): string => SponsorshipGrant::active()
                ->whereHas('venue', fn (Builder $query) => $query->where('city_id', $cityId))
                ->orderBy('id')
                ->pluck('id')
                ->toJson(),
        );
    }

    /**
     * Chiamata dal modello a ogni scrittura: una concessione creata, sospesa o
     * cancellata deve cambiare le pagine subito, non al prossimo minuto.
     */
    public static function forget(int $cityId): void
    {
        Cache::forget(self::key($cityId));
    }

    private static function key(int $cityId): string
    {
        return 'sponsorizzazioni:concessioni-attive:'.$cityId;
    }
}
