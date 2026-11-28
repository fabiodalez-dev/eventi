<?php

declare(strict_types=1);

/*
 * R11 — Catalogo (R-CT-01…07).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: categories, tags, event-features, cities, pages.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\CatalogController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * L'ingresso dell'area: le voci che la persona vede e le scelte dei moduli.
 */
Route::get('/catalog', [CatalogController::class, 'overview'])->middleware('throttle:60,1,editorial-catalog')->name('catalog.index');
Route::get('/catalog/choices', [CatalogController::class, 'choices'])->middleware('throttle:60,1,editorial-catalog')->name('catalog.choices');

/*
 * Le cinque voci hanno le stesse rotte; la voce la porta `section`. `{id}`
 * è sempre l'id numerico (le risorse usano lo slug come chiave di rotta sul
 * sito, qui no). Eliminare resta solo sul sito (decisione 4: è
 * definitivo, nessuna delle cinque voci ha il cestino): nessuna rotta
 * `destroy`. Creare o modificare una città è pericoloso (una città nuova
 * attiva compare subito nel sito, una modifica sposta il motore temporale):
 * password recente. `preview` delle pagine prima di `{id}`.
 */
// Dentro una funzione: le variabili non escono in `routes/api.php`, che include anche gli altri file.
(function (): void {
    $sections = ['categories' => 'categories', 'tags' => 'tags', 'event-features' => 'event_features', 'cities' => 'cities', 'pages' => 'pages'];

    Route::post('/pages/preview', [CatalogController::class, 'previewPage'])->middleware('throttle:60,1,editorial-catalog-preview')->name('catalog.pages.preview');

    foreach ($sections as $path => $section) {
        $name = 'catalog.'.str_replace('_', '-', $section);
        Route::get('/'.$path, [CatalogController::class, 'index'])->defaults('section', $section)
            ->middleware('throttle:60,1,editorial-catalog')->name($name.'.index');
        $store = Route::post('/'.$path, [CatalogController::class, 'store'])->defaults('section', $section)->name($name.'.store');
        $section === 'cities'
            ? $store->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-catalog-save'])
            : $store->middleware('throttle:20,1,editorial-catalog-save');
        Route::get('/'.$path.'/{id}', [CatalogController::class, 'show'])->defaults('section', $section)->whereNumber('id')
            ->middleware('throttle:60,1,editorial-catalog')->name($name.'.show');
        $update = Route::patch('/'.$path.'/{id}', [CatalogController::class, 'update'])->defaults('section', $section)->whereNumber('id')
            ->name($name.'.update');
        $section === 'cities'
            ? $update->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-catalog-save'])
            : $update->middleware('throttle:30,1,editorial-catalog-save');
    }
})();

// Unire due tag è pericoloso: il doppione sparisce.
Route::post('/tags/{id}/merge', [CatalogController::class, 'mergeTag'])->whereNumber('id')
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-catalog-merge'])->name('catalog.tags.merge');
