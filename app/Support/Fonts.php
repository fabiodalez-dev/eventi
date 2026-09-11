<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Il carattere della pagina, per poterlo chiedere **prima** che serva.
 *
 * Archivo arriva da `@fontsource-variable`, importato dentro `app.css`. Comodo,
 * ma crea una catena in serie: il browser scarica l'HTML, poi il CSS, e solo
 * dopo averlo letto scopre che gli serve un `.woff2`. Tre viaggi di rete uno
 * dietro l'altro, e nel frattempo i titoli — che qui sono enormi e in grassetto
 * 800 — restano nel carattere di ripiego. È l'elemento più grande sopra la
 * piega: finché non arriva il font, il tempo di disegno non si ferma.
 *
 * Un `preload` nell'intestazione rompe la catena: il font parte **insieme** al
 * foglio di stile invece che dopo.
 *
 * **Il `crossorigin` non è facoltativo**, nemmeno per un file dello stesso
 * dominio: i font si scaricano sempre in modalità anonima, e un preload
 * dichiarato senza quell'attributo finisce in una cache diversa da quella in
 * cui il CSS andrà a cercarlo. Il file viene scaricato due volte e il preload,
 * invece di guadagnare tempo, lo fa perdere.
 */
final class Fonts
{
    /**
     * Il percorso del carattere latino, o `null` se la build non lo contiene.
     *
     * Torna `null` invece di sollevare: se un domani il pacchetto cambia il
     * nome dei propri file, la pagina deve perdere il preload — non spegnersi.
     * Il font continuerebbe ad arrivare dal CSS come sempre.
     */
    public static function latin(string $family = 'archivo'): ?string
    {
        $files = [
            'archivo' => 'archivo/files/archivo-latin-wght-normal.woff2',
            'manrope' => 'manrope/files/manrope-latin-wght-normal.woff2',
            'bricolage' => 'bricolage-grotesque/files/bricolage-grotesque-latin-standard-normal.woff2',
        ];
        if (! isset($files[$family])) {
            return null;
        }
        try {
            return Vite::asset('node_modules/@fontsource-variable/'.$files[$family]);
        } catch (Throwable) {
            return null;
        }
    }
}
