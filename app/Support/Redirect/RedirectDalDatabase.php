<?php

declare(strict_types=1);

namespace App\Support\Redirect;

use App\Models\City;
use App\Models\Redirect;
use App\Support\CurrentCity;
use Illuminate\Support\Facades\DB;
use Spatie\MissingPageRedirector\Redirector\Redirector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Da dove il pacchetto prende i reindirizzamenti: la tabella `redirects`.
 *
 * Il redirector predefinito legge un array in `config/`, che qui non
 * servirebbe: gli slug cambiano in redazione, non al rilascio. L'interfaccia
 * però prende la richiesta e restituisce un array, quindi una lettura dal
 * database che risolve **un solo** indirizzo per richiesta sta in poche righe.
 * Costa fino a tre letture indicizzate, e solo sui 404.
 *
 * ## Il prefisso di città
 *
 * Le rotte pubbliche sono registrate due volte, nude e sotto `/{city}` (§11.1).
 * Nella tabella il prefisso non c'è; qui viene staccato prima di cercare e
 * rimesso sulla destinazione **esattamente come stava nella richiesta**, così
 * che un indirizzo nudo resti nudo e uno prefissato resti prefissato. È ciò che
 * evita due righe per ogni rinomina.
 *
 * La rinomina di una **città** è il caso opposto e funziona da sé: dopo la
 * rinomina il vecchio slug non corrisponde più a nessuna città accesa, quindi
 * non viene staccato e resta parte del percorso da cercare — dove la riga
 * jolly `/vecchia-citta/*` lo attende.
 */
final class RedirectDalDatabase implements Redirector
{
    /**
     * Le sezioni che non hanno indirizzi da salvare: l'API risponde JSON e un
     * 301 verso una pagina HTML sarebbe una risposta che nessun client sa
     * leggere; il pannello, Livewire e l'installer non hanno indirizzi
     * pubblici che qualcuno possa aver messo fra i preferiti.
     *
     * @var list<string>
     */
    private const SEZIONI_ESCLUSE = ['api', 'admin', 'gestione', 'livewire', 'installazione'];

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function getRedirectsFor(Request $request): array
    {
        /*
         * Il middleware guarda solo lo stato della risposta e non il metodo,
         * quindi un POST finito in 404 arriva fin qui. Non diventerebbe
         * comunque un 301 — `MissingPageRouter` registra la rotta al volo con
         * `get()`, e un POST su una rotta GET esce dal suo `dispatch()` come
         * metodo non ammesso, che il pacchetto ingoia lasciando in piedi il 404
         * (verificato togliendo queste righe: il comportamento non cambia).
         * Restano qui per non pagare una query su un invio che non ha comunque
         * un indirizzo vecchio da onorare.
         */
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return [];
        }

        $percorso = '/'.trim($request->getPathInfo(), '/');

        if ($percorso === '/') {
            return [];
        }

        $primoSegmento = explode('/', ltrim($percorso, '/'))[0];

        if (in_array($primoSegmento, self::SEZIONI_ESCLUSE, true)) {
            return [];
        }

        [$prefisso, $percorsoNudo, $cityId] = $this->staccaLaCitta($percorso, $primoSegmento);

        $riga = Redirect::perPercorso($cityId, $percorsoNudo);

        if ($riga === null) {
            return [];
        }

        $this->contaIlPassaggio($riga);

        /*
         * La chiave dev'essere il percorso **come è arrivato**: il pacchetto ci
         * registra sopra una rotta al volo e poi ci fa passare la richiesta, e
         * una rotta che non combacia non risponderebbe. Per le righe jolly è
         * invece il modello con l'asterisco, che il pacchetto traduce in
         * `{wildcard}` e rimette nella destinazione.
         */
        $origine = $riga->is_wildcard ? $prefisso.$riga->from_path : $percorso;

        return [$origine => [$prefisso.$riga->to_path, $riga->status]];
    }

    /**
     * Stacca il primo segmento se è lo slug di una città accesa: è la stessa
     * lettura che fa `ResolveCity`, e deve restare la stessa — una città spenta
     * è un 404 anche qui, non uno spazio di indirizzi valido.
     *
     * **Senza prefisso la città non è nessuna: è quella predefinita.** Le rotte
     * pubbliche sono registrate due volte e la copia nuda appartiene alla prima
     * città accesa (§11.1, `CurrentCity`). Leggere `null` al suo posto
     * significherebbe cercare solo fra le righe valide ovunque, e non trovare
     * mai quelle di un evento — che sono per forza di una città, perché lo slug
     * di un evento è unico dentro la sua e non nel sistema.
     *
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function staccaLaCitta(string $percorso, string $primoSegmento): array
    {
        $city = City::query()->active()->where('slug', $primoSegmento)->first();

        if ($city === null) {
            return ['', $percorso, $this->cittaPredefinita()];
        }

        $nudo = mb_substr($percorso, mb_strlen('/'.$primoSegmento));

        return ['/'.$primoSegmento, $nudo === '' ? '/' : $nudo, (int) $city->getKey()];
    }

    private function cittaPredefinita(): ?int
    {
        $city = app(CurrentCity::class)->get();

        return $city === null ? null : (int) $city->getKey();
    }

    /**
     * Un passaggio in più su questa riga.
     *
     * Si conta qui e non ascoltando `RouteWasHit` perché quell'evento porta il
     * percorso già tradotto e senza il prefisso di città: risalire alla riga
     * vorrebbe dire rifare al contrario il lavoro appena fatto, e sbagliarlo
     * ogni volta che una delle due parti cambia.
     *
     * `DB::table` e non il modello: questa non è una modifica della riga, è un
     * contatore. Passando dal modello si aggiornerebbe anche `updated_at`, e
     * l'elenco in redazione direbbe che la riga è stata «modificata» da
     * qualcuno che non l'ha toccata.
     */
    private function contaIlPassaggio(Redirect $riga): void
    {
        DB::table('redirects')
            ->where('id', $riga->getKey())
            ->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }
}
