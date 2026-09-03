<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventOccurrence;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * La locandina di un evento, in PDF e con il QR che riporta al sito.
 *
 * **A cosa serve.** Un locale che organizza una serata stampa un foglio e lo
 * attacca in vetrina. Se lo compone da sé, l'evento sul muro e quello sul sito
 * divergono al primo cambio d'orario; se lo compone il sito, il QR riporta
 * sempre alla scheda aggiornata — e chi passa davanti scopre che il sito
 * esiste. È il ponte più economico fra la città fisica e la piattaforma.
 *
 * **DomPDF e non Browsershot.** Browsershot disegna meglio ma pretende Chrome
 * e Node sul server: su hosting condiviso non c'è modo di installarli, e qui
 * si è già visto che nemmeno Vite parte. DomPDF è PHP puro e per un A4 con un
 * titolo, una data e un quadrato nero è quanto serve.
 *
 * **Il QR è SVG, non PNG.** La versione raster di `simple-qrcode` vuole
 * Imagick col supporto PNG; l'SVG è testo, DomPDF lo disegna senza
 * dipendenze, e alla stampa resta nitido a qualunque dimensione.
 */
final class EventPoster
{
    /** Il QR di un evento, come SVG. */
    public function qr(EventOccurrence $occurrence, int $lato = 220): string
    {
        return (string) QrCode::format('svg')
            ->size($lato)
            /* Il margine non è decorazione: i lettori hanno bisogno di una
               cornice chiara per trovare i bordi del codice. */
            ->margin(1)
            ->errorCorrection('M')
            ->generate($this->url($occurrence));
    }

    /**
     * Lo stesso QR come indirizzo `data:`, che è la forma che DomPDF disegna.
     *
     * **Perché non l'SVG scritto in linea.** La prima versione lo metteva
     * dentro la pagina con `{!! !!}`: il PDF si generava, pesava 878 KB e
     * nello spazio del codice c'era il vuoto. DomPDF disegna gli SVG solo
     * quando arrivano come `<img src>`, e un elemento che non sa disegnare lo
     * salta in silenzio — nessun errore, nessun avviso, una locandina senza
     * la cosa per cui esiste. L'ho visto guardando il PDF, non i log.
     */
    public function qrData(EventOccurrence $occurrence, int $lato = 220): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->qr($occurrence, $lato));
    }

    /** Il PDF della locandina, come stringa binaria. */
    public function pdf(EventOccurrence $occurrence): string
    {
        return Pdf::loadView('pdf.event-poster', [
            'occurrence' => $occurrence,
            'event' => $occurrence->event,
            'qr' => $this->qrData($occurrence, 260),
            'url' => $this->url($occurrence),
        ])->setPaper('a4')->output();
    }

    public function filename(EventOccurrence $occurrence): string
    {
        return str($occurrence->event->title.'-'.$occurrence->starts_at->format('Y-m-d'))
            ->slug()
            ->append('.pdf')
            ->value();
    }

    private function url(EventOccurrence $occurrence): string
    {
        return route('events.show', $occurrence->event);
    }
}
