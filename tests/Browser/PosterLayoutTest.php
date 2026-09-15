<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\EventUrl;
use Tests\Support\ImageFixtures;

it('fills each card edge to edge with aligned portrait covers across listings', function (string $theme, int $width): void {
    config(['filesystems.disks.public.url' => '/storage']);
    if (! is_link(public_path('storage'))) {
        $this->artisan('storage:link')->assertSuccessful();
    }
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00');
    $category = testCategory();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $dates = [];
    foreach ([[300, 400], [600, 300], null] as $i => $size) {
        $date = occurrenceAtLocal($city, $category, '2026-09-12 21:00', event: ['title' => 'Locandina di prova '.$i, 'poster' => null], venue: $venue);
        if ($size !== null) {
            $date->event->addMedia(ImageFixtures::upload('poster.png', ImageFixtures::png(...$size)))->toMediaCollection('poster');
        }
        $dates[] = $date;
    }
    foreach (['/', '/eventi', '/mappa?all_dates=1', EventUrl::occurrence($dates[0]), '/locali/'.$venue->slug] as $path) {
        $page = visit($path)->{$theme}()->resize($width, 900);
        $page->script('document.querySelector("[data-consent-banner]")?.remove()');
        if ($path === '/') {
            expect($page->script(<<<'JS'
                () => {
                    const frames = [...document.querySelectorAll('[data-home-page] .event-card .event-poster-frame')];
                    const missing = frames.filter(frame => frame.querySelector('[data-event-hero-placeholder]'));
                    return missing.length > 0 && frames.every(frame => {
                        const rect = frame.getBoundingClientRect();
                        return frame.querySelector('[data-event-hero-placeholder]')
                            ? rect.height <= 160
                            : Math.abs(rect.width / rect.height - .75) < .01;
                    }) && document.documentElement.scrollWidth <= innerWidth;
                }
                JS))->toBeTrue();
            continue;
        }
        /*
         * La locandina riempie il RIQUADRO DI CONTENUTO della card, non la card.
         *
         * Prima si pretendeva che la riempisse da bordo a bordo, ed era giusto
         * finche' i due temi condividevano lo stesso impianto: nel tabellone
         * scuro la card non ha margine interno orizzontale, quindi contenuto e
         * bordo coincidono. Il tema chiaro e' fatto di riquadri staccati: la
         * card ha dieci pixel di margine e la locandina sta dentro, arrotondata.
         *
         * Misurare contro il margine interno letto dal browser, invece che
         * contro un numero scritto qui, dice la stessa cosa in entrambi i temi —
         * «la locandina occupa tutto lo spazio che la card le lascia» — e
         * smetterebbe di valere davvero se quello spazio venisse sprecato.
         */
        expect($page->script('async () => { const cards=[...document.querySelectorAll(".event-card")]; if (!cards.length) return false; for (const card of cards) { const frame=card.querySelector(".event-poster-frame"); if (!frame) return false; const r=frame.getBoundingClientRect(); const c=card.getBoundingClientRect(); const s=getComputedStyle(card); const pl=parseFloat(s.paddingLeft), pr=parseFloat(s.paddingRight), pt=parseFloat(s.paddingTop), pb=parseFloat(s.paddingBottom); const compact=innerWidth>=1024 && card.closest("[data-event-browser]"); if(compact) { if(r.width<158 || r.width>198 || r.left<c.left || r.right>=c.right || Math.abs((c.height-pt-pb)-r.height)>2) return false; } else { if(Math.abs((c.width-pl-pr)-r.width)>2 || Math.abs((c.left+pl)-r.left)>2 || Math.abs(r.width/r.height-.75)>.01) return false; } const img=frame.querySelector("img"); if (img) { img.scrollIntoView({block:"center"}); await new Promise(requestAnimationFrame); await img.decode(); if (!img.naturalWidth || getComputedStyle(img).objectFit!=="cover") return false; } } return document.documentElement.scrollWidth<=innerWidth; }'))->toBeTrue();
        if ($path === EventUrl::occurrence($dates[0])) {
            expect($page->script('async () => { const image=document.querySelector(".event-poster-original img"); image.scrollIntoView({block:"center"}); await new Promise(requestAnimationFrame); await image.decode(); const r=image.getBoundingClientRect(); return Math.abs(r.width/r.height-image.naturalWidth/image.naturalHeight)<.01; }'))->toBeTrue();
        }
        if ($path === '/eventi') {
            expect($page->script('() => { const frames=[...document.querySelectorAll(".event-card .event-poster-frame")].map(e=>e.getBoundingClientRect()); if(frames.length<3) return false; if(innerWidth>=1024) { const grid=document.querySelector("[data-event-browser] .event-grid"); return getComputedStyle(grid).gap==="12px" && parseFloat(getComputedStyle(grid).paddingTop)===12; } return Math.max(...frames.map(r=>r.height))-Math.min(...frames.map(r=>r.height))<2; }'))->toBeTrue();
            $page->screenshot(filename: 'portrait-posters-'.$theme.'-'.$width);
        }
    }
})->with(['inLightMode', 'inDarkMode'])->with([391, 1280]);
