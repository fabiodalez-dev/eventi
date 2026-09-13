<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\EventUrl;
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
        }
    }
})->with(['inLightMode', 'inDarkMode'])->with([375, 804, 1440]);

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
    foreach (['button', 'a:nth-of-type(1)', 'a:nth-of-type(2)', 'a:nth-of-type(3)'] as $target) {
        $selector = '.share-links--icons > '.$target;
        $page->hover($selector);
        expect($page->script('() => { const el=document.querySelector("'.$selector.'"); const css=getComputedStyle(el); return css.color === "rgb(48, 32, 23)" && css.backgroundColor === "rgb(255, 193, 151)" && getComputedStyle(el.querySelector("svg")).color === css.color; }'))->toBeTrue();
    }
    $page->screenshot(filename: 'share-hover-'.$theme);
})->with(['inLightMode', 'inDarkMode']);
