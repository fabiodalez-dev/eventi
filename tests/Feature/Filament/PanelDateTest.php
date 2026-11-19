<?php

declare(strict_types=1);

use App\Filament\Support\PanelDate;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/*
 * Le date dei pannelli (`PanelDate`): una scrittura sola, «sab 21 nov,
 * 21:00», sempre nel fuso della città. Il 19 novembre 2026 è un giovedì.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
});

it('scrive la data di un evento nel fuso della città, con l’anno solo se non è quello in corso', function (): void {
    expect(PanelDate::event('2026-11-21 20:00:00', 'Europe/Rome'))->toBe('sab 21 nov, 21:00')
        ->and(PanelDate::event(CarbonImmutable::parse('2026-11-21 20:00:00', 'UTC'), 'America/New_York'))->toBe('sab 21 nov, 15:00')
        ->and(PanelDate::event('2025-11-22 20:00:00', 'Europe/Rome'))->toBe('sab 22 nov 2025, 21:00')
        ->and(PanelDate::event('2027-01-02 20:00:00', 'Europe/Rome'))->toBe('sab 2 gen 2027, 21:00');
});

it('senza fuso usa quello dei pannelli, non l’UTC dell’applicazione', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(PanelDate::event('2026-11-21 20:00:00'))->toBe('sab 21 nov, 21:00')
        ->and(PanelDate::day('2026-11-20 23:30:00'))->toBe('sab 21 nov');
});

it('scrive un trattino per i valori vuoti', function (): void {
    foreach (['event', 'day', 'moment', 'full'] as $method) {
        expect(PanelDate::{$method}(null))->toBe('—')->and(PanelDate::{$method}(''))->toBe('—');
    }
    expect(PanelDate::range(null, null))->toBe('—')
        ->and(PanelDate::period(null, null))->toBe('—')
        ->and(PanelDate::axis(null))->toBe('—')
        ->and(PanelDate::month(null))->toBe('—');
});

it('scrive inizio e fine di una serata, anche dopo mezzanotte', function (): void {
    expect(PanelDate::range('2026-11-21 20:00:00', '2026-11-21 22:30:00', 'Europe/Rome'))->toBe('sab 21 nov, 21:00–23:30')
        ->and(PanelDate::range('2026-11-21 20:00:00', '2026-11-22 01:00:00', 'Europe/Rome'))->toBe('sab 21 nov, 21:00–02:00')
        ->and(PanelDate::range('2026-11-21 20:00:00', null, 'Europe/Rome'))->toBe(PanelDate::event('2026-11-21 20:00:00', 'Europe/Rome'))
        ->and(PanelDate::range('2026-11-21 20:00:00', '2026-11-23 20:00:00', 'Europe/Rome'))->toBe('sab 21 nov, 21:00 – lun 23 nov, 21:00');
});

it('scrive un giorno senza l’ora', function (): void {
    expect(PanelDate::day('2026-11-21 20:00:00', 'Europe/Rome'))->toBe('sab 21 nov')
        ->and(PanelDate::day('2025-11-22 10:00:00', 'Europe/Rome'))->toBe('sab 22 nov 2025');
});

it('dice da quanto è successa una cosa fino a sette giorni, poi la data', function (): void {
    $now = CarbonImmutable::now('UTC');

    expect(PanelDate::moment($now->subSeconds(20)))->toBe('adesso')
        ->and(PanelDate::moment($now->subMinute()))->toBe('un minuto fa')
        ->and(PanelDate::moment($now->subMinutes(5)))->toBe('5 minuti fa')
        ->and(PanelDate::moment($now->subHour()))->toBe("un'ora fa")
        ->and(PanelDate::moment($now->subHours(2)))->toBe('2 ore fa')
        ->and(PanelDate::moment('2026-11-18 13:30:00', 'Europe/Rome'))->toBe('ieri alle 14:30')
        ->and(PanelDate::moment('2026-11-16 09:00:00', 'Europe/Rome'))->toBe('3 giorni fa')
        ->and(PanelDate::moment('2026-11-09 13:30:00', 'Europe/Rome'))->toBe('9 nov, 14:30')
        ->and(PanelDate::moment('2025-11-09 13:30:00', 'Europe/Rome'))->toBe('9 nov 2025, 14:30');
});

it('scrive un periodo con le preposizioni giuste', function (): void {
    expect(PanelDate::period('2026-10-21 10:00:00', '2026-11-19 10:00:00', 'Europe/Rome'))->toBe('dal 21 ott al 19 nov')
        ->and(PanelDate::period(null, '2026-11-19 10:00:00', 'Europe/Rome'))->toBe('dall\'inizio al 19 nov')
        ->and(PanelDate::period('2026-11-08 10:00:00', '2026-12-11 10:00:00', 'Europe/Rome'))->toBe('dall\'8 nov all\'11 dic')
        ->and(PanelDate::period('2026-11-01 10:00:00', null, 'Europe/Rome'))->toBe('dall\'1 nov');
});

it('scrive le etichette degli assi e i mesi', function (): void {
    expect(PanelDate::axis('2026-10-21'))->toBe('21 ott')
        ->and(PanelDate::axis('2026-10-01', monthly: true))->toBe('ott 2026')
        ->and(PanelDate::month('2026-09-14'))->toBe('settembre 2026')
        ->and(PanelDate::full('2026-11-21 20:00:00', 'Europe/Rome'))->toBe('sabato 21 novembre 2026, 21:00');
});

it('dà a colonne e voci le scorciatoie panelDate e panelMoment', function (): void {
    expect(TextColumn::hasMacro('panelDate'))->toBeTrue()
        ->and(TextColumn::hasMacro('panelMoment'))->toBeTrue()
        ->and(TextEntry::hasMacro('panelDate'))->toBeTrue()
        ->and(TextEntry::hasMacro('panelMoment'))->toBeTrue();

    $column = TextColumn::make('starts_at')->panelDate(fn (): string => 'America/New_York');
    expect($column->formatState('2026-11-21 20:00:00'))->toBe('sab 21 nov, 15:00')
        ->and($column->getTooltip('2026-11-21 20:00:00'))->toBe('sabato 21 novembre 2026, 15:00');

    $moment = TextColumn::make('created_at')->panelMoment();
    expect($moment->formatState(CarbonImmutable::now('UTC')->subHours(2)))->toBe('2 ore fa');
});
