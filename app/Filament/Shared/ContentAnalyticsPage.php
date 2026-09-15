<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\StatsPeriod;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Analytics\ManagementAnalytics;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

abstract class ContentAnalyticsPage extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'statistiche';

    protected string $view = 'filament.content-analytics';

    #[Url]
    public string $period = '';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return ($tenant instanceof Venue || $tenant instanceof Organizer)
            && auth()->user()?->canAccessTenant($tenant) === true;
    }

    public static function getNavigationLabel(): string
    {
        return __('analytics.title');
    }

    public function getTitle(): string
    {
        return __('analytics.title');
    }

    public function getSubheading(): ?string
    {
        return __('analytics.subheading', ['days' => $this->selectedPeriod()->days()]);
    }

    public function setPeriod(string $period): void
    {
        $this->period = (StatsPeriod::tryFrom($period) ?? StatsPeriod::default())->value;
        $this->resetPage();
    }

    public function selectedPeriod(): StatsPeriod
    {
        return StatsPeriod::tryFrom($this->period) ?? StatsPeriod::default();
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return StatsPeriod::options();
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return app(ManagementAnalytics::class)->report($this->selectedPeriod());
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        return $this->report()['totals'];
    }

    /** @return Collection<int, Event> */
    public function events(): Collection
    {
        return app(ManagementAnalytics::class)->events($this->selectedPeriod())->limit(25)->get();
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function eventPage(): LengthAwarePaginator
    {
        return app(ManagementAnalytics::class)->events($this->selectedPeriod())->paginate(25);
    }
}
