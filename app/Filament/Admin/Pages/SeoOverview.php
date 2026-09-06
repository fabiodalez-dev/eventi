<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\City;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SeoOverview extends Page
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $city = City::query()->first();
        $this->getSchema('form')->fill(['city_id' => $city?->id, 'verification' => $city?->getAttribute('seo')['google_verification'] ?? null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('seo.search_console.title'))->description(__('seo.search_console.help'))->schema([
                Select::make('city_id')->label(__('seo.search_console.city'))
                    ->options(City::query()->pluck('name', 'id'))->required()->exists('cities', 'id')->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('verification', City::query()->whereKey($state)->first()?->getAttribute('seo')['google_verification'] ?? null)),
                TextInput::make('verification')->label(__('seo.search_console.code'))
                    ->helperText(__('seo.search_console.code_help'))->maxLength(255)->regex('/^[A-Za-z0-9_-]+$/')->nullable(),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->getSchema('form')->getState();
        $city = City::findOrFail($state['city_id']);
        $city->update(['seo' => array_replace($city->seo ?? [], ['google_verification' => $state['verification'] ?? null])]);
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
