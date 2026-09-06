<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;
use Illuminate\Support\Facades\Cache;

/**
 * Il numero di versione del programma di una città: **il solo segnale di
 * invalidazione** di tutte le cache di §12.3.
 *
 * §12.3 chiede che tre cose diverse — lo scheletro delle pagine, i conteggi
 * del calendario, la mappa del sito — siano «invalidate alla pubblicazione di
 * un evento». Tenere tre elenchi di chiavi da cancellare significa dimenticarne
 * uno: quale mese tocchi un evento con ricorrenza annuale, quali liste filtrate
 * lo contengano, in quale pagina della mappa finisca sono domande che non hanno
 * una risposta economica.
 *
 * Qui la risposta è un'altra: la chiave di ogni cache porta dentro questo
 * numero. Cambiarlo rende irraggiungibili in un colpo solo tutte le voci della
 * città, e quelle vecchie scadono da sole. Costa **una** scrittura per
 * salvataggio.
 *
 * Cache-aside con chiave versionata, non cancellazione: è ciò che permette a
 * `file` di comportarsi come `redis` senza `tags()`, che il driver `file` non
 * supporta (D5).
 */
final class ContentVersion
{
    public static function bumpTaxonomies(): void
    {
        Cache::forget('tassonomie:categorie');
        Cache::forget('tassonomie:tag');
        foreach (City::query()->pluck('id') as $id) {
            self::bump((int) $id);
        }
    }

    /**
     * Il numero corrente. Zero è un valore legittimo: significa che da questa
     * città non è ancora stato pubblicato niente da quando la cache è viva.
     */
    public static function for(City|int $city): int
    {
        $version = Cache::get(self::key(self::id($city)), 0);

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Fa invecchiare tutto ciò che riguarda una città. La chiamano gli
     * observer di `Event` e di `EventOccurrence`: sono il punto attraversato
     * da ogni salvataggio, comunque sia avvenuto — pannello, import o comando.
     */
    public static function bump(City|int $city): void
    {
        $id = self::id($city);

        Cache::forever(self::key($id), self::for($id) + 1);
    }

    private static function id(City|int $city): int
    {
        return $city instanceof City ? (int) $city->getKey() : $city;
    }

    private static function key(int $cityId): string
    {
        return 'contenuti:versione:'.$cityId;
    }
}
