<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Geocoder\Provider\Nominatim\Nominatim;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * La traduzione degli indirizzi con Nominatim, il servizio di OpenStreetMap.
 *
 * **Perché serviva.** Approvando la richiesta di un locale si scriveva il
 * centro della città come coordinate — l'indirizzo c'era, ma nessuno lo
 * traduceva. Un locale a due chilometri dal centro compariva in centro, e la
 * ricerca «vicino a me» rispondeva sul posto sbagliato.
 *
 * **Nominatim e non Google.** È il servizio di OpenStreetMap, senza chiave e
 * senza fattura, e il sito già disegna le mappe con quei dati: usare due
 * fonti diverse per posizionare e per disegnare produce punti che non stanno
 * dove dovrebbero. In cambio c'è un limite d'uso di **una richiesta al
 * secondo**, che per approvare qualche locale al giorno non si sfiora nemmeno.
 *
 * **La cache non è per la velocità, è per rispetto del limite.** Lo stesso
 * indirizzo chiesto due volte — si salva, si riapre, si salva di nuovo — è la
 * cosa più facile da fare per sbaglio, e la più facile da evitare.
 */
final class NominatimGeocoder implements AddressGeocoder
{
    private const TTL = 2592000; // trenta giorni: gli indirizzi non si spostano

    public function __construct(private readonly ?ClientInterface $client = null) {}

    /**
     * Le coordinate di un indirizzo, o `null` se non si trovano.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordinate(string $indirizzo, ?string $comune = null, ?string $paese = 'Italia'): ?array
    {
        $completo = trim(implode(', ', array_filter([$indirizzo, $comune, $paese])));

        if ($completo === '') {
            return null;
        }

        /** @var array{lat: float, lng: float}|null */
        return Cache::remember('geo:'.md5($completo), self::TTL, function () use ($completo): ?array {
            /*
             * Un guasto qui non deve fermare chi sta approvando un locale: si
             * registra e si risponde «non trovato», che il chiamante sa
             * gestire ricadendo sul centro della città. Il contrario — un
             * errore che risale — legherebbe la moderazione alla
             * raggiungibilità di un servizio esterno.
             */
            try {
                $risultati = $this->provider()->geocodeQuery(
                    GeocodeQuery::create($completo)->withLimit(1)
                );

                if ($risultati->isEmpty()) {
                    return null;
                }

                $punto = $risultati->first()->getCoordinates();

                if ($punto === null) {
                    return null;
                }

                return ['lat' => $punto->getLatitude(), 'lng' => $punto->getLongitude()];
            } catch (Throwable $e) {
                Log::warning('Geocodifica non riuscita', [
                    'indirizzo' => $completo,
                    'errore' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    private function provider(): Nominatim
    {
        /*
         * Lo `User-Agent` non è una formalità: la politica d'uso di Nominatim
         * pretende che identifichi l'applicazione, e le richieste anonime
         * vengono rifiutate. Si usa il nome del sito e il suo indirizzo.
         */
        return Nominatim::withOpenStreetMapServer(
            $this->client ?? new Client(['timeout' => 8]),
            (string) config('app.name').' ('.(string) config('app.url').')',
        );
    }
}
