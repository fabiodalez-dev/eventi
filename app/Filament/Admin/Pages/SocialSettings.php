<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\City;
use App\Models\SocialConnection;
use App\Services\Social\SocialPublisher;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class SocialSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.social.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Social::canAccess();
    }

    public function getTitle(): string
    {
        return __('social.settings_title');
    }

    public function mount(): void
    {
        $connection = SocialConnection::first();
        $values = $connection?->toArray() ?? ['city_id' => City::first()?->id, 'graph_version' => 'v25.0', 'publish_time' => '09:00', 'caption' => __('social.default_template')];
        $values['graphic_options'] = array_merge(['monochrome' => true, 'show_address' => true, 'show_price' => true, 'image_fit' => 'contain'], $values['graphic_options'] ?? []);
        unset($values['id'], $values['verified_at'], $values['created_at'], $values['updated_at']);
        $this->getSchema('form')->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->statePath('data')->components([
            Section::make(__('social.settings_title'))->description(__('social.settings_lead'))->schema([
                Select::make('city_id')->label(__('social.city'))->options(City::pluck('name', 'id'))->required(),
                TextInput::make('page_id')->label(__('social.page_id'))->regex('/^\d+$/')->maxLength(80)->required(fn (Get $get) => $get('facebook_enabled') || $get('instagram_enabled')),
                TextInput::make('instagram_id')->label(__('social.instagram_id'))->regex('/^\d+$/')->maxLength(80)->required(fn (Get $get) => $get('instagram_enabled')),
                TextInput::make('access_token')->label(__('social.token'))->password()->autocomplete('off')->helperText(__('social.token_help'))->maxLength(4096),
                TextInput::make('graph_version')->label(__('social.graph_version'))->regex('/^v\d+\.\d+$/')->required(),
                Toggle::make('facebook_enabled')->label(__('social.facebook_enabled')),
                Toggle::make('instagram_enabled')->label(__('social.instagram_enabled')),
            ])->columns(2),
            Section::make(__('social.automatic'))->description(__('social.automatic_help'))->schema([
                Toggle::make('automatic')->label(__('social.automatic')),
                Toggle::make('graphic_options.monochrome')->label(__('social.monochrome'))->default(true),
                Toggle::make('graphic_options.show_address')->label(__('social.address'))->default(true),
                Toggle::make('graphic_options.show_price')->label(__('social.price'))->default(true),
                Select::make('graphic_options.image_fit')->label(__('social.image_fit'))->options(['contain' => __('social.contain'), 'cover' => __('social.cover')])->default('contain')->required(),
                TextInput::make('publish_time')->label(__('social.publish_time'))->type('time')->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
                Textarea::make('caption')->label(__('social.template'))->rows(8)->required()->maxLength(2200)
                    ->aboveContent(fn () => view('filament.social.caption-chips'))->helperText(__('social.template_help')),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $values = $this->getSchema('form')->getState();
        preg_match_all('/(?<!\w):([a-z_]+)/u', $values['caption'], $matches);
        $unknown = array_diff($matches[1], SocialPublisher::TOKENS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['data.caption' => __('social.invalid_tokens', ['tokens' => implode(', ', $unknown)])]);
        }
        $connection = SocialConnection::first() ?? new SocialConnection;
        if (blank($values['access_token'] ?? null)) {
            unset($values['access_token']);
        }
        $connection->fill($values);
        if ($connection->isDirty(['access_token', 'page_id', 'instagram_id', 'graph_version', 'facebook_enabled', 'instagram_enabled'])) {
            $connection->verified_at = null;
        }
        if ($connection->automatic && ! $connection->verified_at) {
            $connection->automatic = false;
            Notification::make()->title(__('social.not_connected'))->warning()->send();
        }
        $connection->save();
        $this->data['access_token'] = '';
        $this->data['automatic'] = $connection->automatic;
        Notification::make()->title(__('social.saved'))->success()->send();
    }

    public function verify(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->save();
        try {
            $connection = SocialConnection::firstOrFail();
            abort_unless($connection->access_token && ($connection->facebook_enabled || $connection->instagram_enabled), 422);
            app(SocialPublisher::class)->verify($connection);
            Notification::make()->title(__('social.verified'))->success()->send();
        } catch (\Throwable) {
            Notification::make()->title(__('social.verify_failed'))->danger()->send();
        }
    }
}
