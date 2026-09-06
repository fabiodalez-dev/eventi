<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Cache\FrontendCacheConfiguration;
use App\Settings\FrontendCacheSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class CachePerformance extends Page
{
    protected string $view = 'filament.cache.performance';

    protected static ?int $navigationSort = 95;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }

    public static function getNavigationLabel(): string
    {
        return __('cache_settings.title');
    }

    public function getTitle(): string
    {
        return __('cache_settings.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $values = app(FrontendCacheSettings::class)->toArray();
        $values['redis_password'] = '';
        $this->getSchema('form')->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('cache_settings.html'))->description(__('cache_settings.html_help'))->schema([
                Toggle::make('managed')->label(__('cache_settings.managed')),
                Toggle::make('enabled')->label(__('cache_settings.enabled')),
                Select::make('store')->label(__('cache_settings.store'))->options([
                    'frontend-file' => __('cache_settings.file'),
                    'frontend-redis' => __('cache_settings.redis'),
                ])->required()->in(['frontend-file', 'frontend-redis']),
                TextInput::make('ttl_minutes')->label(__('cache_settings.ttl'))->numeric()->integer()->minValue(1)->maxValue(5)->required(),
            ]),
            Section::make(__('cache_settings.redis_title'))->description(__('cache_settings.redis_help'))->schema([
                TextInput::make('redis_host')->label(__('cache_settings.host'))->required()->maxLength(253)->regex('/^[a-zA-Z0-9.:-]+$/'),
                TextInput::make('redis_port')->label(__('cache_settings.port'))->numeric()->integer()->minValue(1)->maxValue(65535)->required(),
                TextInput::make('redis_database')->label(__('cache_settings.database'))->numeric()->integer()->minValue(0)->maxValue(15)->required(),
                TextInput::make('redis_username')->label(__('cache_settings.username'))->maxLength(255)->nullable(),
                TextInput::make('redis_password')->label(__('cache_settings.password'))->password()->autocomplete('new-password')->maxLength(1024)->helperText(__('cache_settings.password_help')),
                Toggle::make('redis_tls')->label(__('cache_settings.tls')),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('clear')->label(__('cache_settings.clear'))->color('warning')
            ->requiresConfirmation()->modalHeading(__('cache_settings.clear'))
            ->modalDescription(__('cache_settings.clear_help'))->action(fn () => $this->clearCache())];
    }

    public function clearCache(): void
    {
        abort_unless(static::canAccess(), 403);
        app(FrontendCacheConfiguration::class)->clear();
        Notification::make()->title(__('cache_settings.cleared'))->success()->send();
    }

    public function verify(): void
    {
        abort_unless(static::canAccess(), 403);
        $success = app(FrontendCacheConfiguration::class)->verifyRedis($this->values());
        Notification::make()->title(__($success ? 'cache_settings.redis_ok' : 'cache_settings.redis_failed'))
            ->status($success ? 'success' : 'danger')->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $values = $this->values();
        if ($values['managed'] && $values['enabled'] && $values['store'] === 'frontend-redis'
            && ! app(FrontendCacheConfiguration::class)->verifyRedis($values)) {
            throw ValidationException::withMessages(['data.store' => __('cache_settings.redis_failed')]);
        }
        $settings = app(FrontendCacheSettings::class);
        $settings->fill($values)->save();
        app(FrontendCacheConfiguration::class)->clear();
        $this->data['redis_password'] = '';
        Notification::make()->title(__('cache_settings.saved'))->success()->send();
    }

    /** @return array<string, mixed> */
    private function values(): array
    {
        $values = $this->getSchema('form')->getState();
        if (blank($values['redis_password'] ?? null)) {
            $values['redis_password'] = app(FrontendCacheSettings::class)->redis_password;
        }

        return $values;
    }
}
