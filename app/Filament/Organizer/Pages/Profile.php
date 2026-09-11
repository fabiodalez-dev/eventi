<?php

namespace App\Filament\Organizer\Pages;

use App\Filament\Support\DescriptionEditor;
use App\Models\Organizer;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** @property-read Schema $form */
class Profile extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $title = 'Profilo pubblico';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected string $view = 'filament.organizer.profile';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer && $tenant->is_active && (int) $tenant->owner_id === (int) auth()->id();
    }

    private function organizer(): Organizer
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organizer && static::canAccess(), 403);

        return $tenant;
    }

    public function mount(): void
    {
        $this->form->fill($this->organizer()->only(['description', 'website', 'email']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->statePath('data')->components([
            DescriptionEditor::make('description')->label('Presentazione')->maxLength(20000),
            TextInput::make('website')->label('Sito web')->url()->maxLength(2048),
            TextInput::make('email')->label('Email pubblica')->email()->maxLength(255),
        ]);
    }

    public function save(): void
    {
        $this->organizer()->update($this->form->getState());
        Notification::make()->success()->title('Profilo aggiornato')->send();
    }
}
