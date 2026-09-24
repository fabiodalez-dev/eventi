<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Venue;
use App\Support\EventUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('aligns every event card track without clipping variable content', function (string $theme, int $width): void {
    Http::fake(['*' => Http::response([], 503)]);
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $category = testCategory();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $dates = [];
    foreach (['Jazz', 'Un concerto con un titolo molto lungo per verificare tutte le righe della card', 'Musica in piazza', 'Una serata insieme'] as $i => $title) {
        $dates[] = occurrenceAtLocal($city, $category, '2026-09-13 21:00', event: ['title' => $title, 'price_type' => 'free'], venue: $venue);
    }
    DB::table('saved_events')->insert([
        'user_id' => User::factory()->create()->id, 'occurrence_id' => $dates[1]->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $dates[1]->update(['highlight' => 'Ingresso dal cortile']);
    foreach (['/', '/eventi', EventUrl::occurrence($dates[0]), '/locali/'.$venue->slug, '/cerca?q=musica'] as $path) {
        $page = visit($path)->{$theme}()->resize($width, 900);
        $page->script('document.querySelector("[data-consent-banner]")?.remove()');
        expect($page->script('() => {
            const grids = [...document.querySelectorAll(".event-grid")];
            for (const grid of grids) {
                const rows = new Map();
                for (const card of grid.querySelectorAll(":scope > .event-card")) {
                    const top = Math.round(card.getBoundingClientRect().top);
                    rows.set(top, [...(rows.get(top) || []), card]);
                    const heading = card.querySelector(".event-card__title");
                    if (heading.scrollHeight > heading.clientHeight + 1) return false;
                    if (card.querySelector(".event-card__category > span:not(.ui-tag)")) return false;
                    const footer = card.querySelector(".event-card__footer");
                    const price = footer.firstElementChild.getBoundingClientRect();
                    const actions = footer.lastElementChild.getBoundingClientRect();
                    if (Math.abs((price.top + price.bottom) / 2 - (actions.top + actions.bottom) / 2) > 1) return false;
                    const badge = card.querySelector("[data-interest-id]");
                    if (!badge?.closest("[data-catalog-poster]") || !badge.closest("[data-catalog-poster]").querySelector("svg")) return false;
                }
                for (const cards of rows.values()) {
                    for (const slot of ["category", "title", "venue", "date", "details", "footer"]) {
                        const tops = cards.map(card => card.querySelector(".event-card__" + slot).getBoundingClientRect().top);
                        if (Math.max(...tops) - Math.min(...tops) > 1) return false;
                    }
                }
            }
            return document.documentElement.scrollWidth <= innerWidth;
        }'))->toBeTrue();
        if ($path === '/eventi') {
            $page->assertSee('Un concerto con un titolo molto lungo')->screenshot(filename: 'aligned-cards-'.$theme.'-'.$width);
            if ($width === 1440) {
                $page->hover('.event-card:first-child');
                /*
                 * Al passaggio del puntatore la freccia REAGISCE; come, dipende
                 * dal tema.
                 *
                 * Nello scuro e' sciolta in fondo alla card e scivola a destra:
                 * li' scivolare vuol dire «avanti». Nel chiaro sta dentro un
                 * disco pieno, e la stessa traslazione la porterebbe fuori dal
                 * centro del proprio cerchio — si vede subito, ed e' l'unica
                 * cosa che si vede. Li' cresce e basta.
                 *
                 * Quanto scivola lo dichiara il foglio di stile con
                 * `--card-arrow-shift`; `motion.js` lo legge invece di sapere
                 * quale tema e' acceso. L'ingrandimento invece vale in
                 * entrambi: e' il segnale che il comando ha ricevuto il
                 * puntatore, e quello non si toglie.
                 */
                $scivolamento = $theme === 'inLightMode' ? 'Math.abs(transform.m41)<1' : 'transform.m41>5';
                expect($page->script('async () => { const deadline = performance.now() + 3000; do { await new Promise(resolve => setTimeout(resolve, 50)); const card=document.querySelector(".event-card"); const arrow=card.querySelector("[data-card-arrow]"); const save=card.querySelector("[data-save-button][data-save-variant=icon]"); const icon=arrow.querySelector("svg"); const transform=new DOMMatrix(getComputedStyle(icon).transform); if (parseFloat(getComputedStyle(arrow).borderTopWidth)===0 && getComputedStyle(save).boxShadow==="none" && '.$scivolamento.' && transform.a>1) return true; } while (performance.now() < deadline); return false; }'))->toBeTrue();
            }
        }
    }
})->with(['inLightMode', 'inDarkMode'])->with([375, 666, 804, 1440]);

it('aligns venue names metadata descriptions and badges in both themes', function (string $theme): void {
    $city = testCity();
    foreach (['Spazio Uno', 'Un locale dal nome molto lungo che occupa più righe', 'Spazio Tre'] as $i => $name) {
        Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => $name, 'short_description' => $i === 1 ? str_repeat('Uno spazio aperto alla città. ', 5) : null, 'is_nonprofit' => $i === 0]);
    }
    $page = visit('/locali')->{$theme}()->resize(1280, 900);
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    expect($page->script('() => {
        const cards = [...document.querySelectorAll(".venue-grid > .venue-card")];
        if (cards.length !== 3) return false;
        for (const selector of ["h3", ".venue-card__heading p", ".venue-card__description", ".venue-card__footer"]) {
            const tops = cards.map(card => card.querySelector(selector).getBoundingClientRect().top);
            if (Math.max(...tops) - Math.min(...tops) > 1) return false;
        }
        return document.documentElement.scrollWidth <= innerWidth;
    }'))->toBeTrue();
    $page->screenshot(filename: 'aligned-venues-'.$theme);
})->with(['inLightMode', 'inDarkMode']);

it('keeps every hero sharing icon readable on hover', function (string $theme): void {
    Http::fake(['*' => Http::response([], 503)]);
    $city = testCity();
    $date = occurrenceAtLocal($city, testCategory(), now('Europe/Rome')->addDay()->format('Y-m-d').' 18:00');
    $page = visit(EventUrl::occurrence($date))->{$theme}()->resize(1280, 900);
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->script('const style=document.createElement("style"); style.textContent=".share-links * { transition: none !important; }"; document.head.append(style)');
    $foreground = $theme === 'inDarkMode' ? 'rgb(11, 11, 11)' : 'rgb(48, 32, 23)';
    $background = $theme === 'inDarkMode' ? 'rgb(204, 255, 0)' : 'rgb(255, 193, 151)';
    foreach (['button', 'a:nth-of-type(1)', 'a:nth-of-type(2)', 'a:nth-of-type(3)'] as $target) {
        $selector = '.share-links--icons > '.$target;
        $page->hover($selector);
        expect($page->script('() => { const el=document.querySelector("'.$selector.'"); const css=getComputedStyle(el); return css.color === "'.$foreground.'" && css.backgroundColor === "'.$background.'" && getComputedStyle(el.querySelector("svg")).color === css.color; }'))->toBeTrue();
    }
    $page->screenshot(filename: 'share-hover-'.$theme);
})->with(['inLightMode', 'inDarkMode']);

it('uses the theme accent in the home photo feature', function (string $theme): void {
    $city = testCity();
    occurrenceAtLocal($city, testCategory(), now('Europe/Rome')->addDay()->format('Y-m-d').' 18:00');
    $page = visit('/')->{$theme}();
    $accent = $theme === 'inDarkMode' ? 'rgb(204, 255, 0)' : 'rgb(255, 193, 151)';
    expect($page->script('() => getComputedStyle(document.querySelector(".home-poster-feature .bg-accent")).backgroundColor'))->toBe($accent);
})->with(['inLightMode', 'inDarkMode']);
