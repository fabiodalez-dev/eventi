<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\City;
use App\Services\Seo\SeoAudit;
use App\Support\CurrentCity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

/**
 * SEO e qualità del catalogo: il collegamento con Search Console e il
 * controllo degli ultimi duecento eventi pubblici.
 *
 * Il controllo era un elenco di duecento righe una sotto l'altra, tutte con lo
 * stesso peso, quelle a posto comprese: per trovare cosa sistemare bisognava
 * scorrerle tutte. Ora si legge a pagine di venti, e la scheda «Da
 * sistemare» — quella predefinita — mostra solo gli eventi con qualcosa che
 * non va.
 */
class SeoOverview extends Page
{
    use WithPagination;

    public const PER_PAGE = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.analytics');
    }

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public array $data = [];

    /** `issues` (solo gli eventi da sistemare) oppure `all`. */
    public string $filter = 'issues';

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['issues', 'all'], true)) {
            $this->filter = 'issues';
        }

        $this->resetPage();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $report = collect(app(SeoAudit::class)->report());
        $withIssues = $report->filter(fn (array $row): bool => $row['issues'] !== []);
        $rows = $this->filter === 'all' ? $report : $withIssues;
        $page = $this->getPage();

        return [
            'rows' => new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page),
            'counts' => ['issues' => $withIssues->count(), 'all' => $report->count()],
        ];
    }

    public function mount(): void
    {
        $city = City::query()->first();
        $this->getSchema('form')->fill(['city_id' => $city?->id, 'verification' => $city?->getAttribute('seo')['google_verification'] ?? null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('seo.search_console.title'))->description(__('seo.search_console.help'))->columns(['default' => 1, 'lg' => 2])->schema([
                Select::make('city_id')->label(__('seo.search_console.city'))
                    ->options(City::query()->pluck('name', 'id'))->required()->exists('cities', 'id')->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('verification', City::query()->whereKey($state)->first()?->getAttribute('seo')['google_verification'] ?? null)),
                TextInput::make('verification')->label(__('seo.search_console.code'))
                    ->helperText(__('seo.search_console.code_help'))->maxLength(255)->regex('/^[A-Za-z0-9_-]+$/')->nullable(),
            ])->footerActions([
                Action::make('saveVerification')->label(__('seo.search_console.save'))->action(fn () => $this->save()),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->getSchema('form')->getState();
        $city = City::findOrFail($state['city_id']);
        $city->update(['seo' => array_replace($city->seo ?? [], ['google_verification' => $state['verification'] ?? null])]);
        $currentCity = app(CurrentCity::class);
        if ($currentCity->get()?->id === $city->id) {
            $currentCity->set($city);
        }
        Notification::make()->title(__('seo.search_console.saved'))->success()->send();
    }

    protected string $view = 'filament.seo.overview';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }

    public static function getNavigationLabel(): string
    {
        return __('seo.audit.title');
    }

    public function getTitle(): string
    {
        return __('seo.audit.title');
    }
}
