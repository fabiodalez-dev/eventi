<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Editorial\CacheSettingsController;
use App\Http\Controllers\Api\V1\Editorial\ConsentScriptController;
use App\Http\Controllers\Api\V1\Editorial\CookieDeclarationController;
use App\Http\Controllers\Api\V1\Editorial\MailSettingsController;
use App\Http\Controllers\Api\V1\Editorial\NewsletterSettingsController;
use App\Http\Controllers\Api\V1\Editorial\NotificationTextController;
use App\Http\Controllers\Api\V1\Editorial\RedirectController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * R13 — Configurazione tecnica (R-SY-08…14).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: notification-texts, mail, newsletter, consent-scripts,
 * cookies, redirects, cache.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 *
 * Decisione 5: lettura e azioni operative nell'app (svuota e verifica la
 * cache, manda la prova della posta); la scrittura dei segreti e del codice
 * (SMTP, script di consenso, Redis) resta sul sito. Si scrivono dall'app i
 * testi delle email, la newsletter, il registro dei cookie e gli indirizzi
 * cambiati. Il pannello non elimina niente in queste pagine; chiedono
 * però la password recente (revisione dell'ondata 3) i testi delle email e
 * il loro ripristino, che partono dall'indirizzo del sito verso tutti, e la
 * newsletter, che va a tutti gli iscritti.
 */

// Testi delle email (R-SY-08): admin e super admin
Route::get('/notification-texts', [NotificationTextController::class, 'index'])->middleware('throttle:60,1,editorial-settings-texts')->name('settings.texts.index');
Route::post('/notification-texts/preview', [NotificationTextController::class, 'preview'])->middleware('throttle:120,1,editorial-settings-texts-preview')->name('settings.texts.preview');
Route::post('/notification-texts/reset', [NotificationTextController::class, 'reset'])
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-settings-texts-reset'])->name('settings.texts.reset');
Route::put('/notification-texts/{key}', [NotificationTextController::class, 'update'])->where('key', '[a-z0-9_]+(\.[a-z0-9_]+)+')
    ->middleware([RequireRecentPassword::class, 'throttle:30,1,editorial-settings-texts-update'])->name('settings.texts.update');

// Posta (R-SY-09): solo super admin; le credenziali si scrivono dal sito
Route::get('/mail', [MailSettingsController::class, 'show'])->middleware('throttle:60,1,editorial-settings-mail')->name('settings.mail.show');
Route::post('/mail/test', [MailSettingsController::class, 'test'])->middleware('throttle:5,1,editorial-settings-mail-test')->name('settings.mail.test');

// Newsletter (R-SY-10): solo super admin
Route::get('/newsletter', [NewsletterSettingsController::class, 'show'])->middleware('throttle:60,1,editorial-settings-newsletter')->name('settings.newsletter.show');
Route::put('/newsletter', [NewsletterSettingsController::class, 'update'])
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-settings-newsletter-update'])->name('settings.newsletter.update');

// Script di consenso (R-SY-11): sola lettura, admin e super admin
Route::get('/consent-scripts', [ConsentScriptController::class, 'index'])->middleware('throttle:60,1,editorial-settings-scripts')->name('settings.scripts.index');
Route::get('/consent-scripts/{script}', [ConsentScriptController::class, 'show'])->whereNumber('script')->middleware('throttle:60,1,editorial-settings-scripts-show')->name('settings.scripts.show');

// Cookie (R-SY-12): admin e super admin
Route::get('/cookies', [CookieDeclarationController::class, 'index'])->middleware('throttle:60,1,editorial-settings-cookies')->name('settings.cookies.index');
Route::post('/cookies', [CookieDeclarationController::class, 'store'])->middleware('throttle:30,1,editorial-settings-cookies-store')->name('settings.cookies.store');
Route::post('/cookies/order', [CookieDeclarationController::class, 'reorder'])->middleware('throttle:30,1,editorial-settings-cookies-reorder')->name('settings.cookies.reorder');

// Indirizzi cambiati (R-SY-13): `pages.manage`
Route::get('/redirects', [RedirectController::class, 'index'])->middleware('throttle:60,1,editorial-settings-redirects')->name('settings.redirects.index');
Route::get('/redirects/options', [RedirectController::class, 'options'])->middleware('throttle:60,1,editorial-settings-redirects-options')->name('settings.redirects.options');
Route::post('/redirects', [RedirectController::class, 'store'])->middleware('throttle:30,1,editorial-settings-redirects-store')->name('settings.redirects.store');

// Cache e prestazioni (R-SY-14): admin e super admin; le impostazioni si scrivono dal sito
Route::get('/cache', [CacheSettingsController::class, 'show'])->middleware('throttle:60,1,editorial-settings-cache')->name('settings.cache.show');
Route::post('/cache/clear', [CacheSettingsController::class, 'clear'])->middleware('throttle:10,1,editorial-settings-cache-clear')->name('settings.cache.clear');
Route::post('/cache/verify', [CacheSettingsController::class, 'verify'])->middleware('throttle:10,1,editorial-settings-cache-verify')->name('settings.cache.verify');
