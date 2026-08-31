<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

/**
 * §2.1 delle convenzioni: «Nessuna stringa UI hardcoded. Ogni testo passa da
 * `lang/it/*.php` con `__()`».
 *
 * La regola ha un rovescio che nessuno controlla: una chiave **scritta male**
 * non fa fallire niente. `__('events.filtri.gratis')` al posto di
 * `__('events.filters.free')` non solleva alcun errore — Laravel restituisce
 * la chiave stessa, e la pagina mostra `events.filtri.gratis` a chi la visita.
 * `PanelRenderingTest` copre questo per il pannello, ma solo per le pagine che
 * quel test apre: qui si guardano **tutti** i Blade, compresi quelli che
 * nessun test rende mai — le email, gli stati d'errore, i rami condizionali.
 *
 * Si leggono le sole chiavi scritte come stringa letterale: una chiave
 * composta a runtime (`'enums.venue_type.'.$tipo->value`) non è verificabile
 * da qui, e viene coperta dagli enum, che hanno i propri test.
 */

/**
 * @return array<string, list<string>> chiave => file in cui compare
 */
function chiaviDiTraduzioneNeiBlade(): array
{
    $chiavi = [];

    $file = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $info */
    foreach ($file as $info) {
        if (! $info->isFile() || ! str_ends_with($info->getFilename(), '.blade.php')) {
            continue;
        }

        $contenuto = (string) file_get_contents($info->getPathname());

        /*
         * L'apice di chiusura deve essere seguito da `,` o da `)`: senza quel
         * vincolo la ricerca prenderebbe anche il pezzo iniziale di una chiave
         * composta a runtime — `__('calendar.weekdays.'.$giorno)` darebbe
         * `calendar.weekdays.`, che non esiste e non deve esistere.
         */
        foreach (["/(?:__|@lang|trans|trans_choice)\(\s*'([a-zA-Z0-9_.\-]+)'\s*[,)]/", '/(?:__|@lang|trans|trans_choice)\(\s*"([a-zA-Z0-9_.\-]+)"\s*[,)]/'] as $schema) {
            if (preg_match_all($schema, $contenuto, $trovate) === 0) {
                continue;
            }

            foreach ($trovate[1] as $chiave) {
                $chiavi[$chiave][] = str_replace(resource_path('views').'/', '', $info->getPathname());
            }
        }
    }

    ksort($chiavi);

    return $chiavi;
}

it('trova nei Blade un numero di chiavi che ha senso, altrimenti non sta controllando niente', function (): void {
    expect(count(chiaviDiTraduzioneNeiBlade()))->toBeGreaterThan(200);
});

it('non usa nei Blade nemmeno una chiave che lang/it non ha', function (): void {
    $mancanti = [];

    foreach (chiaviDiTraduzioneNeiBlade() as $chiave => $file) {
        /* Una chiave senza punto non è una chiave di file di lingua: è una
           frase passata a `__()` come testo, che Laravel restituisce tale e
           quale. Non è il caso di questo progetto, ma non è un errore di
           chiave. */
        if (! str_contains($chiave, '.')) {
            continue;
        }

        if (! Lang::has($chiave, 'it')) {
            $mancanti[$chiave] = array_values(array_unique($file));
        }
    }

    expect($mancanti)->toBe([]);
});

/**
 * Il rovescio del rovescio: una chiave che esiste ma è **vuota** stampa il
 * nulla, e in pagina si vede un pulsante senza scritta.
 */
it('non ha nei Blade una chiave che esiste ma non dice niente', function (): void {
    $vuote = [];

    foreach (array_keys(chiaviDiTraduzioneNeiBlade()) as $chiave) {
        if (! str_contains($chiave, '.') || ! Lang::has($chiave, 'it')) {
            continue;
        }

        $valore = Lang::get($chiave, [], 'it');

        if (is_string($valore) && trim($valore) === '') {
            $vuote[] = $chiave;
        }
    }

    expect($vuote)->toBe([]);
});

/**
 * `lang/en` e `lang/de` esistono e restano vuoti (§2.1): non è una svista, è
 * una decisione. Il presidio serve perché una traduzione parziale è peggio di
 * nessuna traduzione — la pagina esce metà in una lingua e metà nell'altra.
 */
it('tiene le altre lingue vuote, come dichiarato nelle convenzioni', function (string $lingua): void {
    $percorso = lang_path($lingua);

    expect(is_dir($percorso))->toBeTrue();

    /* `.gitkeep` esiste perché la cartella vuota resti nel repository: è il
       modo in cui si dichiara che la lingua è prevista e non tradotta. */
    $file = array_values(array_diff((array) scandir($percorso), ['.', '..', '.gitkeep']));

    expect($file)->toBe([]);
})->with(['inglese' => 'en', 'tedesco' => 'de']);
