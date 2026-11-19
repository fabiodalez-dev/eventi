<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Support\PosterColumn;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;

/*
 * Le viste comuni dei pannelli: `stat-cards` (i numeri con la loro frase) e
 * `event-row` (la riga di un evento con la locandina).
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
});

it('disegna i numeri con etichetta, frase e chiave, alla maniera italiana', function (): void {
    $html = Blade::render('<x-pannelli::stat-cards :items="$items" label="I numeri del mese" />', ['items' => [
        ['key' => 'views', 'label' => 'Visite alla scheda', 'value' => 6805, 'hint' => 'Quante volte qualcuno ha aperto la scheda.'],
        ['key' => 'rate', 'label' => 'Clic ogni 100 viste', 'value' => 33.3, 'hint' => 'Su cento viste, quanti clic.'],
        ['key' => 'saved', 'label' => 'Salvati', 'value' => null, 'hint' => 'Compare quando qualcuno salva un evento.'],
    ]]);

    expect($html)->toContain('data-stat-cards')
        ->toContain('aria-label="I numeri del mese"')
        ->toContain('data-stat="views"')->toContain('Visite alla scheda')->toContain('6.805')->toContain('Quante volte qualcuno ha aperto la scheda.')
        ->toContain('data-stat="rate"')->toContain('33,3')
        ->toContain('data-stat="saved"')->toContain('Ancora nessun dato')
        ->not->toContain('n.d.');

    $included = view('filament.partials.stat-cards', ['items' => [['key' => 'k', 'label' => 'Etichetta', 'value' => 12, 'hint' => 'Frase']]])->render();
    expect($included)->toContain('data-stat="k"')->toContain('Etichetta')->toContain('Frase');
});

it('disegna la riga di un evento con il segnaposto, la data leggibile e gli slot', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => 'Officina dei Portici']);
    $event = Event::factory()->create(['title' => 'Jazz & blues sotto i portici', 'city_id' => $city->id, 'venue_id' => $venue->id, 'status' => EventStatus::Published]);
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-11-21 20:00:00', 'UTC')]);

    $html = Blade::render(<<<'BLADE'
        <x-pannelli::event-row :event="$event" venue="Officina dei Portici" href="/scheda" size="lg" :bar="140">
            <x-slot:badges><span class="badge-di-prova">Online</span></x-slot:badges>
            <span class="riga-in-piu">Una riga in più</span>
            <x-slot:trailing><strong class="numero-di-prova">212</strong></x-slot:trailing>
        </x-pannelli::event-row>
        BLADE, ['event' => $event->fresh()]);

    expect($html)->toContain('data-event-row')
        ->toContain('href="/scheda"')
        ->toContain('src="'.e(asset(PosterColumn::PLACEHOLDER)).'"')
        ->toContain('width="72" height="96"')
        ->toContain('Jazz &amp; blues sotto i portici')
        ->toContain('sab 21 nov, 21:00 · Officina dei Portici')
        ->toContain('badge-di-prova')->toContain('riga-in-piu')->toContain('numero-di-prova')
        ->toContain('width: 100%');

    $plain = view('filament.partials.event-row', ['event' => $event->fresh(), 'when' => '2026-12-05 20:00:00', 'size' => 'sm'])->render();
    expect($plain)->toContain('sab 5 dic, 21:00')->toContain('width="36" height="48"')->not->toContain('<a ')
        ->not->toContain('incitta-event-row-bar');

    $empty = Event::factory()->create(['title' => 'Bozza senza date', 'city_id' => $city->id, 'venue_id' => $venue->id]);
    expect(view('filament.partials.event-row', ['event' => $empty])->render())->toContain('Senza date');
});
