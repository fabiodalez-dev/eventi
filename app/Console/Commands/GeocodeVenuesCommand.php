<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Venue;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Console\Command;

/**
 * Ripara le coordinate dei locali che stanno sul centro della loro città.
 *
 * Prima che esistesse la geocodifica, ogni locale approvato da una richiesta
 * riceveva il punto del capoluogo: sulla mappa si accatastano tutti nello
 * stesso posto, e la ricerca «vicino a me» risponde sul luogo sbagliato.
 *
 * **Non tocca chi ha coordinate proprie.** Chi ha spostato il segnaposto a
 * mano ha fatto una scelta piu' informata di qualunque servizio automatico:
 * si interviene solo dove il punto coincide col centro, che e' la firma del
 * valore messo d'ufficio.
 */
final class GeocodeVenuesCommand extends Command
{
    protected $signature = 'venues:geocode
                            {--dry-run : mostra cosa cambierebbe senza salvare}
                            {--limit=50 : quanti locali al massimo}';

    protected $description = 'Assegna le coordinate vere ai locali che stanno sul centro della città';

    public function handle(AddressGeocoder $geocoder): int
    {
        $daFare = Venue::query()
            ->with('city')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->get()
            /* Il confronto è sulle sette cifre decimali con cui la colonna
               salva: un locale spostato a mano di pochi metri non coincide
               piu' col centro, ed è giusto che non venga toccato. */
            ->filter(fn (Venue $v): bool => $v->city !== null
                && (float) $v->lat === (float) $v->city->center_lat
                && (float) $v->lng === (float) $v->city->center_lng)
            ->take((int) $this->option('limit'));

        if ($daFare->isEmpty()) {
            $this->info('Nessun locale da correggere.');

            return self::SUCCESS;
        }

        $this->info($daFare->count().' locali stanno sul centro della città.');
        $riusciti = 0;

        foreach ($daFare as $locale) {
            $punto = $geocoder->coordinate((string) $locale->address, $locale->municipality);

            if ($punto === null) {
                $this->line("  · {$locale->name}: indirizzo non trovato");

                continue;
            }

            $this->line(sprintf('  ✓ %s → %.5f, %.5f', $locale->name, $punto['lat'], $punto['lng']));

            if (! $this->option('dry-run')) {
                $locale->forceFill($punto)->save();
                $riusciti++;
            }

            /* Il limite d'uso di Nominatim è una richiesta al secondo, e
               rispettarlo è la condizione per continuare a usarlo. Un ciclo
               senza pausa su cinquanta locali lo violerebbe cinquanta volte
               in pochi secondi. */
            usleep(1_100_000);
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? 'Prova a vuoto: niente salvato.'
            : "Corretti {$riusciti} locali.");

        return self::SUCCESS;
    }
}
