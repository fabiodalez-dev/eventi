<?php

declare(strict_types=1);

use App\Livewire\LiveNow;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mostra "in corso" con il badge che pulsa solo quando c\'è davvero qualcosa', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', '2026-09-05 23:30:00', event: [
        'title' => 'Concerto di prova',
    ]);

    Livewire::withoutLazyLoading()->test(LiveNow::class)
        ->assertSee(__('events.sections.ongoing'))
        ->assertSee(__('events.badge.ongoing'))
        ->assertSeeHtml('pulse-dot')
        ->assertSee('Concerto di prova');
});

it('marca "inizia tra poco" con il tempo che manca', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 20:25:00');

    Livewire::withoutLazyLoading()->test(LiveNow::class)
        ->assertSee(__('events.sections.starting_soon'))
        ->assertSee(__('events.badge.starting_soon', ['countdown' => '25 min']));
});

/* §8.6: nessuna delle due sezioni si disegna se la finestra è vuota. */
it('non disegna alcuna sezione quando non c\'è niente in corso né in arrivo', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    /* Domani sera: fuori da entrambe le finestre. */
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    Livewire::withoutLazyLoading()->test(LiveNow::class)
        ->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.sections.starting_soon'))
        ->assertDontSee('Nessun evento');
});

it('esclude dalle sezioni dal vivo le categorie che non sopportano il "in corso"', function (): void {
    $city = testCity();
    $mostra = testCategory(['name' => 'Arte e mostre', 'supports_ongoing' => false, 'default_duration_minutes' => 480]);

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $mostra, '2026-09-05 10:00:00', '2026-09-05 19:00:00', event: [
        'title' => 'Mostra permanente',
    ]);

    Livewire::withoutLazyLoading()->test(LiveNow::class)->assertDontSee('Mostra permanente');
});
