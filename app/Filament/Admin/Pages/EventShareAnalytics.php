<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\StatsPeriod;
use App\Services\Analytics\EventShareReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use stdClass;

class EventShareAnalytics extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $slug = 'statistiche-condivisioni';

    protected string $view = 'filament.event-share-analytics';

    #[Url]
    public string $period = 'month';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }

    public static function getNavigationLabel(): string
    {
        return __('event-shares.title');
    }

    public function getTitle(): string
    {
        return __('event-shares.title');
    }

    public function setPeriod(string $period): void
    {
        $this->period = (StatsPeriod::tryFrom($period) ?? StatsPeriod::default())->value;
        $this->resetPage('sharePage');
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    public function shareRows(): LengthAwarePaginator
    {
        return app(EventShareReport::class)->rows(StatsPeriod::tryFrom($this->period) ?? StatsPeriod::default());
    }
}
