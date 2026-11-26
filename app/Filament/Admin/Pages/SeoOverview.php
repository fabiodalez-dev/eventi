<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\City;
use App\Services\Seo\SearchConsoleVerification;
use App\Services\Seo\SeoAudit;
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

    public const PER_PAGE = SeoAudit::PER_PAGE;

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
        if (! in_array($this->filter, SeoAudit::FILTERS, true)) {
            $this->filter = 'issues';
        }

        $this->resetPage();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        // Lo stesso controllo, le stesse schede e le stesse pagine dell'app della redazione.
        $overview = app(SeoAudit::class)->overview($this->filter, $this->getPage(), self::PER_PAGE);

        return ['rows' => $overview['rows'], 'counts' => $overview['counts']];
    }

    public function mount(): void
    {
        $city = City::query()->first();
        $this->getSchema('form')->fill(['city_id' => $city?->id, 'verification' => $city === null ? null : SearchConsoleVerification::code($city)]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('seo.search_console.title'))->description(__('seo.search_console.help'))->columns(['default' => 1, 'lg' => 2])->schema([
                Select::make('city_id')->label(__('seo.search_console.city'))
                    ->options(City::query()->pluck('name', 'id'))->required()->exists('cities', 'id')->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('verification', self::codeOf($state))),
                TextInput::make('verification')->label(__('seo.search_console.code'))
                    ->helperText(__('seo.search_console.code_help'))->maxLength(SearchConsoleVerification::MAX)->regex(SearchConsoleVerification::PATTERN)->nullable(),
            ])->footerActions([
                Action::make('saveVerification')->label(__('seo.search_console.save'))->action(fn () => $this->save()),
            ]),
        ]);
    }

    /** Il codice salvato della città scelta nel campo «Città». */
    private static function codeOf(mixed $cityId): ?string
    {
        $city = City::query()->whereKey($cityId)->first();

        return $city === null ? null : SearchConsoleVerification::code($city);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->getSchema('form')->getState();
        // Lo stesso salvataggio dell'app della redazione: tocca solo il codice.
        app(SearchConsoleVerification::class)->save(City::findOrFail($state['city_id']), $state['verification'] ?? null);
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
