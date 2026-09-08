<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SponsorshipBannerController;
use App\Http\Controllers\Web\CalendarController;
use App\Http\Controllers\Web\CalendarWizardController;
use App\Http\Controllers\Web\EventController;
use App\Http\Controllers\Web\EventListController;
use App\Http\Controllers\Web\EventSubmissionController;
use App\Http\Controllers\Web\FeedController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\MapController;
use App\Http\Controllers\Web\OrganizerController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SearchSuggestionsController;
use App\Http\Controllers\Web\TonightController;
use App\Http\Controllers\Web\VenueApplicationController;
use App\Http\Controllers\Web\VenueController;
use App\Http\Middleware\CachePage;
use App\Http\Middleware\PersonalizeDiscovery;
use Illuminate\Support\Facades\Route;

Route::get('/organizzatori', [OrganizerController::class, 'index'])->name('organizers.index');
Route::get('/organizzatori/{slug}', [OrganizerController::class, 'show'])->name('organizers.show');

/*
|--------------------------------------------------------------------------
| Sito pubblico (§11.1)
|--------------------------------------------------------------------------
|
| Questo file viene incluso **due volte** da routes/web.php: una volta nudo,
| per la città predefinita, e una volta sotto il prefisso `/{city}` con i nomi
| preceduti da `city.`. È così che `/{city}/eventi` è predisposto senza essere
| obbligatorio (§11.1): le rotte sono le stesse, cambia solo chi decide la
| città — il middleware `ResolveCity` invece della prima città accesa.
|
| La full-page cache di §12.3 (`CachePage`) è dichiarata sulle singole rotte e
| non sull'intero file: i moduli, la ricerca libera, i feed e i marcatori della
| mappa non vanno messi in cache — il primo perché porta gli errori di
| validazione di chi lo sta compilando, la seconda perché ogni ricerca è
| diversa, gli ultimi due perché non sono pagine.
|
| L'ordine conta più della simmetria. `/eventi/oggi` deve essere dichiarato
| prima di `/eventi/{date}`, e quella prima di `/eventi/{slug}`: altrimenti la
| rotta più generica si mangia le altre e "oggi" diventa il nome di un evento
| che non esiste.
*/

Route::get('/', HomeController::class)->middleware(CachePage::class)->name('home');
Route::get('/stasera', TonightController::class)->middleware(PersonalizeDiscovery::class)->name('tonight.wizard');

// Web middleware resolves the session; never cache personalized banner responses.
Route::get('/banner-sponsorizzato', SponsorshipBannerController::class)->name('sponsorships.banner');

Route::get('/eventi', [EventListController::class, 'index'])->middleware(CachePage::class)->name('events.index');
Route::get('/eventi/oggi', [EventListController::class, 'today'])->middleware(CachePage::class)->name('events.today');
Route::get('/eventi/domani', [EventListController::class, 'tomorrow'])->middleware(CachePage::class)->name('events.tomorrow');
Route::get('/eventi/weekend', [EventListController::class, 'weekend'])->middleware(CachePage::class)->name('events.weekend');
Route::get('/eventi/gratis', [EventListController::class, 'free'])->middleware(CachePage::class)->name('events.free');
Route::get('/eventi/categoria/{category}', [EventListController::class, 'category'])->middleware(CachePage::class)->name('events.category');
Route::get('/eventi/tag/{tag}', [EventListController::class, 'tag'])->middleware(CachePage::class)->name('events.tag');
Route::get('/eventi/{date}', [EventListController::class, 'onDate'])
    ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->middleware(CachePage::class)
    ->name('events.date');

/*
 * I feed (§11.10). Vanno dichiarati **prima** delle rotte che accettano uno
 * slug: `/eventi.ics` è un indirizzo, non un evento che si chiama "ics".
 */
Route::get('/eventi.ics', [FeedController::class, 'calendar'])->middleware('auth')->name('feeds.calendar');
Route::get('/feed.rss', [FeedController::class, 'rss'])->name('feeds.rss');

/*
 * Mappa (§11.6). La pagina, il carico dei marcatori e la card del foglio
 * inferiore sono tre indirizzi distinti: il primo si può condividere, gli
 * altri due li rilegge la mappa a ogni spostamento.
 */
Route::get('/mappa', [MapController::class, 'index'])->name('map.index');
Route::get('/mappa/marcatori', [MapController::class, 'markers'])->name('map.markers');
Route::get('/mappa/locale/{venue}', [MapController::class, 'venue'])
    ->whereNumber('venue')
    ->name('map.venue');

/*
 * Calendario mensile (§11.8). Senza mese è quello corrente; con il mese è un
 * indirizzo stabile che si può condividere e mettere fra i preferiti.
 */
Route::get('/calendario', CalendarController::class)->name('calendar.index');
Route::get('/calendario/personalizza', CalendarWizardController::class)->name('feeds.wizard');
Route::get('/calendario/{month}', CalendarController::class)
    ->where('month', '[0-9]{4}-[0-9]{2}')
    ->name('calendar.month');

Route::get('/cerca', SearchController::class)->name('search');
Route::get('/cerca/suggerimenti', SearchSuggestionsController::class)
    ->middleware('throttle:120,1')->name('search.suggestions');

Route::get('/eventi/{slug}/segnala', [ReportController::class, 'createForEvent'])->name('events.report');
Route::post('/eventi/{slug}/segnala', [ReportController::class, 'storeForEvent'])
    ->middleware('throttle:public-forms')
    ->name('events.report.store');
Route::get('/eventi/{slug}/date/{occurrence}/calendario.ics', [EventController::class, 'calendar'])->name('events.calendar');
Route::get('/eventi/{slug}/date/{occurrence}', [EventController::class, 'date'])->name('events.occurrence');
Route::get('/eventi/{slug}/date/{occurrence}/locandina.pdf', [EventController::class, 'poster'])->name('events.poster');
Route::get('/eventi/{slug}', [EventController::class, 'show'])->middleware(CachePage::class)->name('events.show');

Route::get('/locali', [VenueController::class, 'index'])->middleware(CachePage::class)->name('venues.index');
Route::get('/locali/{slug}/segnala', [ReportController::class, 'createForVenue'])->name('venues.report');
Route::post('/locali/{slug}/segnala', [ReportController::class, 'storeForVenue'])
    ->middleware('throttle:public-forms')
    ->name('venues.report.store');
Route::get('/locali/{slug}', [VenueController::class, 'show'])->middleware(CachePage::class)->name('venues.show');

Route::get('/proponi-evento', [EventSubmissionController::class, 'create'])->name('submissions.create');
Route::post('/proponi-evento', [EventSubmissionController::class, 'store'])
    ->middleware('throttle:public-forms')
    ->name('submissions.store');

Route::get('/registra-il-tuo-locale', [VenueApplicationController::class, 'create'])->name('venue-applications.create');
Route::post('/registra-il-tuo-locale', [VenueApplicationController::class, 'store'])
    ->middleware('throttle:public-forms')
    ->name('venue-applications.store');
