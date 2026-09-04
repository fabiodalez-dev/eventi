<?php

declare(strict_types=1);

namespace App\Support\Redirect;

use App\Models\Redirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chi scrive nella tabella `redirects`.
 *
 * Sta in una classe sua e non dentro gli observer perché le due trappole di
 * ogni tabella di reindirizzamenti — il ciclo e la catena — si risolvono **in
 * scrittura**, e vanno risolte una volta sola per tutti quelli che scrivono.
 *
 * ## Il ciclo
 *
 * A viene rinominato in B, poi qualcuno ci ripensa e B torna A. Restano due
 * righe che si rimandano a vicenda, e il browser si ferma da solo su «troppi
 * reindirizzamenti»: l'indirizzo che si voleva salvare diventa irraggiungibile
 * proprio per averlo salvato. Si evita cancellando, prima di scrivere, la riga
 * che parte dalla destinazione.
 *
 * ## La catena
 *
 * A diventa B, poi B diventa C. La riga A→B punta a un indirizzo che non
 * esiste più: chi arriva da A fa un salto per finire su un 404. Si evita
 * facendo puntare a C tutto ciò che puntava a B, così ogni vecchio indirizzo
 * costa **un solo salto** — che è anche ciò che i motori di ricerca
 * considerano un reindirizzamento e non una redirezione a catena.
 */
final class RegistroRedirect
{
    /**
     * Registra che `$da` è diventato `$a`.
     *
     * I due percorsi sono senza prefisso di città: `/eventi/vecchio-slug`, non
     * `/padova/eventi/vecchio-slug`. Con `$jolly` la coppia è
     * `/vecchia-citta/*` → `/nuova-citta/{wildcard}`.
     */
    public function registra(?int $cityId, string $da, string $a, bool $jolly = false): void
    {
        if ($da === $a) {
            return;
        }

        /*
         * La forma con cui la destinazione comparirebbe come origine. Per una
         * riga normale è la destinazione stessa; per una jolly il segnaposto
         * del pacchetto (`{wildcard}`) torna a essere l'asterisco con cui le
         * origini sono scritte. Senza questa traduzione il controllo sul ciclo
         * cercherebbe una riga che non può esistere.
         */
        $origineDellaDestinazione = $jolly ? str_replace('{wildcard}', '*', $a) : $a;

        /*
         * E la forma con cui l'origine comparirebbe come destinazione, che
         * serve al contrario: le righe da appiattire puntano a `/B/{wildcard}`,
         * mentre qui `$da` arriva scritto `/B/*`.
         */
        $destinazioneDellOrigine = $jolly ? str_replace('*', '{wildcard}', $da) : $da;

        DB::transaction(function () use ($cityId, $da, $a, $jolly, $origineDellaDestinazione, $destinazioneDellOrigine): void {
            $this->righeDella($cityId)
                ->where('from_path', $origineDellaDestinazione)
                ->delete();

            $this->righeDella($cityId)
                ->where('to_path', $destinazioneDellOrigine)
                ->update(['to_path' => $a]);

            Redirect::query()->updateOrCreate(
                ['city_id' => $cityId, 'from_path' => $da],
                ['to_path' => $a, 'is_wildcard' => $jolly, 'status' => 301],
            );
        });
    }

    /**
     * Le righe di una città, o quelle che non ne hanno una.
     *
     * `whereNull` e non `where('city_id', null)`: in SQL `= NULL` non è mai
     * vero, e una condizione che non è mai vera qui vorrebbe dire cancellare
     * niente e non accorgersene.
     *
     * @return Builder<Redirect>
     */
    private function righeDella(?int $cityId): Builder
    {
        $query = Redirect::query();

        return $cityId === null
            ? $query->whereNull('city_id')
            : $query->where('city_id', $cityId);
    }

    /**
     * La rinomina di una città: un ramo intero, in una riga.
     *
     * `city_id` resta nullo perché la città vecchia, dopo la rinomina, non
     * corrisponde più a nessuno slug: chi arriva su `/vecchia-citta/eventi`
     * non passa da `ResolveCity` con successo — quel segmento è, per il sito,
     * un pezzo di indirizzo come un altro.
     */
    public function registraCitta(string $slugVecchio, string $slugNuovo): void
    {
        $this->registra(
            null,
            '/'.$slugVecchio.'/*',
            '/'.$slugNuovo.'/{wildcard}',
            jolly: true,
        );
    }
}
