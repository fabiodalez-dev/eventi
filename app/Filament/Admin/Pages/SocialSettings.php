<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\City;
use App\Services\Social\SocialSettingsDesk;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
        $this->getSchema('form')->fill(app(SocialSettingsDesk::class)->values());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->statePath('data')->components([
            Section::make(__('social.settings_title'))->description(__('social.settings_lead'))->schema([
                TextInput::make('app_id')->label('Meta App ID')->regex('/^\d+$/')->maxLength(80),
                TextInput::make('app_secret')->label('Meta App Secret')->password()->autocomplete('off')->maxLength(4096)->helperText('Cifrato. Lascia vuoto per mantenere il valore salvato.'),
                Select::make('city_id')->label(__('social.city'))->options(City::pluck('name', 'id'))->required(),
                TextInput::make('page_id')->label(__('social.page_id'))->regex('/^\d+$/')->maxLength(80)->helperText('Compilato dal collegamento Meta; obbligatorio per verificare un token manuale.'),
                TextInput::make('instagram_id')->label(__('social.instagram_id'))->regex('/^\d+$/')->maxLength(80)->helperText('Account Instagram professionale collegato alla Pagina Facebook.'),
                TextInput::make('access_token')->label(__('social.token'))->password()->autocomplete('off')->helperText(__('social.token_help'))->maxLength(4096),
                TextInput::make('graph_version')->label(__('social.graph_version'))->regex('/^v\d+\.\d+$/')->required(),
                Toggle::make('facebook_enabled')->label(__('social.facebook_enabled')),
                Toggle::make('instagram_enabled')->label(__('social.instagram_enabled')),
            ])->columns(2),
            Section::make('Telegram')->description('Crea un bot con @BotFather e aggiungilo come amministratore del canale con permesso di pubblicare. Non serve OAuth.')->schema([
                TextInput::make('telegram_bot_token')->label('Token del bot')->password()->autocomplete('off')->regex('/^\d+:[A-Za-z0-9_-]+$/')->maxLength(4096)->helperText('Cifrato. Lascia vuoto per mantenere il token salvato.'),
                TextInput::make('telegram_chat_id')->label('Canale: @username oppure ID numerico')->regex('/^(@[A-Za-z][A-Za-z0-9_]{4,}|-?\d+)$/')->maxLength(100),
                Toggle::make('telegram_enabled')->label('Pubblica su Telegram'),
            ]),
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
        $saved = app(SocialSettingsDesk::class)->save($this->getSchema('form')->getState(), 'data.caption');
        if ($saved['automatic_off']) {
            Notification::make()->title(__('social.not_connected'))->warning()->send();
        }
        $this->data['access_token'] = '';
        $this->data['app_secret'] = '';
        $this->data['telegram_bot_token'] = '';
        $this->data['automatic'] = $saved['connection']->automatic;
        Notification::make()->title(__('social.saved'))->success()->send();
    }

    public function connectMeta(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->save();
        $this->redirect(route('social.meta.connect'));
    }

    public function disconnectMeta(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SocialSettingsDesk::class)->disconnectMeta();
        session()->forget('meta_oauth');
        session()->forget('meta_pages');
        Notification::make()->title('Collegamento locale rimosso; pubblicazioni automatiche disattivate')->success()->send();
    }

    public function verifyTelegram(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->save();
        $error = app(SocialSettingsDesk::class)->verifyTelegram();
        if ($error === null) {
            Notification::make()->title('Bot e permessi del canale Telegram verificati')->success()->send();
        } else {
            Notification::make()->title($error)->danger()->send();
        }
    }

    public function verify(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->save();
        if (app(SocialSettingsDesk::class)->verifyMeta()) {
            Notification::make()->title(__('social.verified'))->success()->send();
        } else {
            Notification::make()->title(__('social.verify_failed'))->danger()->send();
        }
    }
}
