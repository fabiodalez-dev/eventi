<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\CurrentCity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/**
 * Il manifesto dell'applicazione web (§15.6).
 *
 * È **una rotta e non un file statico**, per la stessa ragione di `robots.txt`:
 * `start_url` e `scope` vogliono indirizzi che dipendono da dove gira il sito,
 * e il nome è `config('app.name')` — che per D10 deve poter cambiare con una
 * riga di `.env`. Un file scritto a mano congelerebbe entrambe le cose al
 * giorno in cui è stato scritto, e il dominio di oggi è provvisorio.
 *
 * ## Cosa cambia, in concreto
 *
 * Con questo file un telefono può tenere il sito nella schermata iniziale:
 * si apre a schermo pieno, con la sua icona e il suo colore, e le notifiche
 * push di §15.6 — che finora arrivavano soltanto a chi aveva la scheda
 * aperta — hanno un posto dove tornare.
 *
 * ## Cosa NON c'è, di proposito
 *
 * Nessun `fetch` nel service worker. `public/sw.js` lo dice già e vale la pena
 * ripeterlo qui, perché è la richiesta che arriva sempre insieme a un
 * manifesto: un worker che serve pagine dalla propria cache è il guasto più
 * difficile da diagnosticare che si possa aggiungere a un sito, e questo sito
 * è già rapido perché le pagine stanno in cache lato server. L'installabilità
 * non ne ha bisogno; il funzionamento offline non è un obiettivo dichiarato.
 */
final class WebManifestController extends Controller
{
    public function __invoke(CurrentCity $currentCity): JsonResponse
    {
        $city = $currentCity->get();
        $app = config()->string('app.name');
        $nomeCitta = $city === null ? '' : $city->name;

        return response()->json([
            /*
             * `id` fissa l'identità dell'applicazione installata. Senza, quella
             * identità è `start_url`, e il giorno in cui cambia il browser
             * crede di trovarsi davanti a un'applicazione diversa: chi l'aveva
             * installata si ritrova due icone.
             */
            /*
             * Relativo di proposito, come `start_url` e `scope`: si risolvono
             * tutti e tre contro l'origine del manifesto. È ciò che rende
             * `padova.incitta.it` e `bologna.incitta.it` due applicazioni
             * distinte e installabili insieme, senza che questo file debba
             * sapere quante città esistono.
             */
            'id' => '/',
            'name' => $nomeCitta === '' ? $app : $app.' '.$nomeCitta,
            /*
             * Sotto l'icona ci stanno una dozzina di caratteri, ed è l'unica
             * cosa che distingue due installazioni.
             *
             * Il giorno in cui esisteranno `padova.incitta.it` e
             * `bologna.incitta.it`, chi le installa entrambe si ritrova due
             * icone **identiche** — stesso disegno, stesso colore — e l'unico
             * appiglio è questa riga: «Padova» e «Bologna» dicono qual è
             * quale, «inCittà» ripetuto due volte no. Il marchio resta nel
             * `name`, che è quello che si legge al momento di installare.
             */
            'short_name' => $nomeCitta === '' ? $app : $nomeCitta,
            'description' => __('ui.footer.about_body', ['app' => $app, 'city' => $nomeCitta]),
            'lang' => str_replace('_', '-', app()->getLocale()),
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            /* I due colori del tema scuro, che è quello predefinito: il
               manifesto ne ammette uno solo e non sa niente delle preferenze
               di chi installa. */
            'background_color' => '#0b0b0b',
            'theme_color' => '#0b0b0b',
            'categories' => ['events', 'entertainment', 'lifestyle'],
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                /*
                 * La variante mascherabile è lo stesso disegno: il fondo è
                 * pieno fino al bordo e il monogramma sta ben dentro il
                 * cerchio di sicurezza, quindi Android può ritagliarlo nella
                 * forma che preferisce senza tagliare niente di utile.
                 */
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            /*
             * Le scorciatoie del menu contestuale dell'icona. Sono le stesse
             * tre domande con cui si apre il sito — cosa c'è stasera, cosa c'è
             * nel weekend, cosa c'è vicino — e si dichiarano solo se la rotta
             * esiste davvero, come ogni altro collegamento del sito.
             */
            'shortcuts' => $this->shortcuts(),
        ], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->withHeaders([
                'Content-Type' => 'application/manifest+json; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shortcuts(): array
    {
        $voci = [
            'events.today' => __('ui.nav.today'),
            'events.weekend' => __('ui.nav.weekend'),
            'map.index' => __('ui.nav.map'),
        ];

        $scorciatoie = [];

        foreach ($voci as $rotta => $etichetta) {
            if (! Route::has($rotta)) {
                continue;
            }

            $scorciatoie[] = [
                'name' => $etichetta,
                'url' => parse_url(route($rotta), PHP_URL_PATH) ?: '/',
                'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']],
            ];
        }

        return $scorciatoie;
    }
}
