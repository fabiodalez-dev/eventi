<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\EventOccurrence;
use App\Services\Cache\LiveWindows;
use App\Support\CurrentCity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\Component;

/**
 * "In corso adesso" e "Inizia tra poco" (§11.2, punti 2 e 3).
 *
 * ## Perché è una vista e non più un componente Livewire
 *
 * §12.3 le voleva differite: la pagina in cache per qualche minuto, queste due
 * finestre in un frammento a parte caricato dopo il primo disegno, così che
 * uno scheletro in cache non mentisse su ciò che sta succedendo adesso. D40 ha
 * poi deciso il contrario — si disegnano dal server — ma la correzione non è
 * mai entrata in vigore, e per due motivi che valeva la pena scrivere.
 *
 * Il primo è che togliere `lazy` dal tag non toglie `#[Lazy]` dalla classe:
 * `SupportLazyLoading` disattiva l'attributo solo davanti a un `lazy` scritto
 * a mano e messo a `false`. Il secondo è più profondo, e non si risolveva
 * togliendo l'attributo: qualunque componente Livewire renderizzato fa
 * iniettare `livewire.min.js` nella pagina — 84 KB in coda alle sei
 * connessioni, la risorsa più pesante della pagina iniziale dopo la locandina.
 *
 * ## Il guasto che questa conversione chiude
 *
 * C'era di peggio del peso. Su una pagina servita dalla cache non si
 * renderizza alcun componente, quindi `SupportAutoInjectedAssets` non inietta
 * lo script — mentre l'HTML salvato contiene il segnaposto. Il frammento non
 * arrivava mai: chi visitava il sito su una copia in cache — cioè quasi
 * tutti — leggeva per sempre «Guardo cosa sta succedendo adesso…» e non
 * vedeva mai le due sezioni.
 *
 * Disegnandole dal server il problema non si pone: entrano nella pagina, e
 * nella sua chiave di cache, insieme a tutto il resto.
 *
 * Le due finestre restano definite in `EventOccurrenceQuery` (§8): qui si
 * chiedono, non si ricalcolano. Fra le due c'è `App\Services\Cache\LiveWindows`,
 * che le tiene per sessanta secondi con la chiave arrotondata al quarto d'ora.
 */
final class LiveNow extends Component
{
    /**
     * Quante card per sezione: chi guarda "adesso" vuole scegliere, non
     * sfogliare un catalogo.
     */
    private const PER_SECTION = 6;

    /** @var Collection<int, EventOccurrence> */
    public Collection $ongoing;

    /** @var Collection<int, EventOccurrence> */
    public Collection $startingSoon;

    public function __construct(LiveWindows $windows, CurrentCity $currentCity)
    {
        $city = $currentCity->get();

        $this->ongoing = $city === null ? new Collection : $windows->ongoing($city, self::PER_SECTION);
        $this->startingSoon = $city === null ? new Collection : $windows->startingSoon($city, self::PER_SECTION);
    }

    public function render(): View
    {
        return view('components.live-now');
    }
}
