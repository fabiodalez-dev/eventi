<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Settings\SponsorshipBannerSettings;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SponsorshipBanners extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Banner sponsorizzati';

    protected static ?string $title = 'Banner sponsorizzati';

    protected string $view = 'filament.sponsorship-banners';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->getSchema('form')->fill(app(SponsorshipBannerSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make('Dove mostrarli')->description('I banner riutilizzano le campagne attive e autorizzate. Nessun costo o pagamento viene creato automaticamente.')->schema([
                Toggle::make('web_enabled')->label('Banner sul sito'),
                Toggle::make('android_enabled')->label('Banner nell’app Android'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SponsorshipBannerSettings::class)->fill($this->getSchema('form')->getState())->save();
        Notification::make()->title('Impostazioni salvate')->success()->send();
    }
}
