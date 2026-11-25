<?php

namespace App\Filament\Organizer\Pages;

use App\Actions\UpdateOrganizerProfile;
use App\Filament\Support\ContactFields;
use App\Filament\Support\DescriptionEditor;
use App\Models\Organizer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** @property-read Schema $form */
class Profile extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.organizer.profile';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getTitle(): string
    {
        return __('panel.organizer_profile.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.organizer_profile.title');
    }

    public function getSubheading(): ?string
    {
        return __('panel.organizer_profile.lead');
    }

    /**
     * Solo chi ha creato l'organizzazione, finché è attiva: la regola è
     * `OrganizerPolicy::updateProfile`, la stessa dell'app.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer && auth()->user()?->can('updateProfile', $tenant) === true;
    }

    private function organizer(): Organizer
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organizer && static::canAccess(), 403);

        return $tenant;
    }

    public function mount(): void
    {
        $this->form->fill($this->organizer()->only(['description', 'website', 'email', 'contact_mode', 'contact_email']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->statePath('data')->components([
            Section::make(__('panel.organizer_profile.presentation'))
                ->description(__('panel.organizer_profile.presentation_hint'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    DescriptionEditor::make('description')->label(__('panel.organizer_profile.description'))->maxLength(UpdateOrganizerProfile::DESCRIPTION_MAX),
                ]),
            Section::make(__('panel.organizer_profile.contacts'))
                ->description(__('panel.organizer_profile.contacts_hint'))
                ->icon('heroicon-o-at-symbol')
                ->columns(1)
                ->schema([
                    TextInput::make('website')->label(__('panel.organizer_profile.website'))->url()->maxLength(2048),
                    TextInput::make('email')->label(__('panel.organizer_profile.email'))->email()->maxLength(255),
                ]),
            ContactFields::make(fn (): Organizer => $this->organizer()),
        ]);
    }

    public function save(): void
    {
        app(UpdateOrganizerProfile::class)->handle($this->organizer(), $this->form->getState());
        Notification::make()->success()->title(__('panel.organizer_profile.saved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')
                ->label(__('panel.organizer_profile.view_public'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('organizers.show', $this->organizer()->slug))
                ->openUrlInNewTab(),
        ];
    }
}
