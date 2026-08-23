<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Enums\StatsPeriod;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Event;
use App\Queries\VenueDashboardQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * §10.5 — visualizzazioni, salvataggi, click su indicazioni, click su
 * biglietti, su 7 / 30 / 90 giorni.
 *
 * **Nessun dato personale**, come prescrive il piano: qui si contano righe
 * aggregate, mai persone. Non esiste un elenco di chi ha salvato un evento
 * né da dove è arrivato: il gestore vede quanto funziona la sua serata, non
 * chi la guarda.
 *
 * Il periodo sta nell'indirizzo: una pagina di numeri deve poter essere
 * ricaricata, condivisa con il socio e ritrovata uguale.
 */
class Statistics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'statistiche';

    protected string $view = 'filament.venue.pages.statistics';

    /**
     * Quanti eventi elencare sotto ai totali: oltre, la pagina diventa un
     * archivio e per l'archivio c'è l'elenco degli eventi.
     */
    private const TOP_EVENTS = 10;

    #[Url]
    public string $period = '';

    public static function getNavigationLabel(): string
    {
        return __('manage.statistics.title');
    }

    public function getTitle(): string
    {
        return __('manage.statistics.title');
    }

    public function getSubheading(): ?string
    {
        return __('manage.statistics.subheading', ['days' => $this->selectedPeriod()->days()]);
    }

    public function setPeriod(string $period): void
    {
        $this->period = (StatsPeriod::tryFrom($period) ?? StatsPeriod::default())->value;
    }

    /**
     * Il periodo scelto, sempre validato: l'indirizzo è modificabile a mano.
     */
    public function selectedPeriod(): StatsPeriod
    {
        return StatsPeriod::tryFrom($this->period) ?? StatsPeriod::default();
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        return VenueDashboardQuery::for(CurrentVenue::get())->totals($this->selectedPeriod());
    }

    /**
     * Gli eventi più visti del periodo. Se il locale non ne ha nessuno con
     * numeri da mostrare, l'elenco non viene disegnato affatto (§8.6).
     *
     * @return Collection<int, Event>
     */
    public function events(): Collection
    {
        /** @var Collection<int, Event> $events */
        $events = VenueDashboardQuery::for(CurrentVenue::get())
            ->eventTotals($this->selectedPeriod())
            ->having('views_total', '>', 0)
            ->orderByDesc('views_total')
            ->limit(self::TOP_EVENTS)
            ->get();

        return $events;
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return StatsPeriod::options();
    }
}
