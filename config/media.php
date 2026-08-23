<?php

declare(strict_types=1);

/*
 * La pipeline media di §12.1:
 *
 *     upload → validazione MIME reale → strip EXIF → resize → WebP + AVIF
 *            → blurhash → CDN
 *
 * Qui stanno i soli numeri della pipeline. Le conversioni vere le dichiarano
 * `Event::registerMediaConversions()` e `Venue::registerMediaConversions()`,
 * che leggono queste larghezze: cambiare `card` in un punto solo deve bastare.
 */
return [

    /*
     * Il tetto di §12.1. È ripetuto in `config/media-library.php`
     * (`max_file_size`), che è il controllo della libreria; questo è quello
     * del modulo, cioè quello che produce un messaggio leggibile invece di
     * un'eccezione.
     */
    'max_upload_bytes' => 12 * 1024 * 1024,

    /*
     * I formati accettati in ingresso (§12.1). **Il tipo si accerta leggendo
     * il file, non l'estensione**: `foto.jpg` può essere uno script PHP, e un
     * `.heic` prodotto da un iPhone arriva spesso dichiarato
     * `application/octet-stream` dal browser.
     *
     * La chiave è il tipo MIME reale, il valore l'elenco delle estensioni che
     * quel tipo può legittimamente avere.
     */
    'accepted' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic', 'heif'],
        'image/heif' => ['heic', 'heif'],
        'image/avif' => ['avif'],
    ],

    /*
     * Sotto queste misure un'immagine non è una locandina: è una miniatura
     * presa da un messaggio, e ingrandita si sgrana. Vale come rifiuto in
     * validazione, non come ridimensionamento.
     */
    'min_width' => 200,
    'min_height' => 200,

    /*
     * Le tre varianti di §12.1, in larghezza. `full` è il tetto: nessuna
     * variante ingrandisce mai un originale più piccolo (`Fit::Max`).
     */
    'variants' => [
        'thumb' => 400,
        'card' => 800,
        'full' => 1600,
    ],

    /*
     * Qualità per formato. AVIF regge una qualità più bassa a parità di resa:
     * è la ragione per cui vale la pena generarlo accanto al WebP.
     */
    'quality' => [
        'webp' => 82,
        'avif' => 55,
        'jpg' => 82,
    ],

    /*
     * Il blurhash (§12.1): il segnaposto sfocato che riempie il riquadro
     * mentre la locandina arriva. Si calcola su un raster minuscolo — 32 px
     * bastano, e sono ciò che rende l'operazione trascurabile.
     */
    'blurhash' => [
        'sample' => 32,
        'components_x' => 4,
        'components_y' => 3,
    ],

    /*
     * L'immagine Open Graph 1200×630 di §12.1, composta con `spatie/image`.
     *
     * `logo` e `font` sono percorsi assoluti o relativi alla radice del
     * progetto; se il file non c'è, quell'elemento semplicemente non viene
     * disegnato — un'anteprima senza logo è meglio di nessuna anteprima.
     */
    'open_graph' => [
        /*
         * L'anteprima si compone in coda ed è costosa. Lo spegnimento serve
         * agli ambienti che non hanno Imagick e alla suite di test, che non
         * deve disegnare un'immagine per ogni evento creato: il test dedicato
         * la riaccende e verifica il file vero.
         */
        'enabled' => filter_var(env('MEDIA_OG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'width' => 1200,
        'height' => 630,
        'disk' => env('MEDIA_DISK', 'public'),
        'directory' => 'og',
        'background' => '#12101a',
        'foreground' => '#ffffff',
        'muted' => '#c9c4d8',
        'accent' => '#7c5cff',
        'font' => env('MEDIA_OG_FONT', 'resources/fonts/og-title.ttf'),
        'text_font' => env('MEDIA_OG_TEXT_FONT', 'resources/fonts/og-text.ttf'),
        'logo' => env('MEDIA_OG_LOGO', ''),
        'title_size' => 58,
        'meta_size' => 30,
        'quality' => 85,
    ],

    /*
     * La `→ CDN` in fondo alla riga di §12.1. Finché è vuota gli indirizzi
     * restano quelli del disco; appena viene riempita, ogni indirizzo di
     * media e di conversione esce dal dominio della rete di distribuzione
     * senza che nessuna vista debba saperlo.
     */
    'cdn_url' => env('MEDIA_CDN_URL'),

];
