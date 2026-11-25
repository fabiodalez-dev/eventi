<?php

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Filament\Organizer\Resources\Events\EventResource;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Events\OrganizerEventEditor;
use App\Services\Import\FacebookEventImport;
use App\Services\Import\FacebookImportThrottle;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /** @var array<string, mixed> */
    #[Locked]
    public array $facebookImport = [];

    protected function getHeaderActions(): array
    {
        return [Action::make('facebook')->label(__('facebook_import.action'))->icon('heroicon-o-arrow-down-tray')
            ->modalHeading(__('facebook_import.action'))->modalDescription(__('facebook_import.url_hint'))
            ->modalSubmitActionLabel(__('facebook_import.load'))
            ->schema([TextInput::make('url')->label(__('facebook_import.url_label'))->required()->url()->maxLength(2048)->placeholder('https://www.facebook.com/events/…')])
            ->action(fn (array $data) => $this->importFacebook($data['url']))];
    }

    public function importFacebook(string $url): void
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        abort_unless($tenant instanceof Organizer && $user instanceof User && $user->can('create', [Event::class, $tenant]), 403);
        // Gli stessi secchi del pannello del locale e dell'app.
        $limit = app(FacebookImportThrottle::class)->attempt($user);
        if ($limit !== null) {
            Notification::make()->warning()->title(__('facebook_import.'.$limit))->send();

            return;
        }
        $import = app(FacebookEventImport::class);
        $photo = null;
        try {
            $source = $import->fetch($url);
            $city = City::find($this->data['city_id'] ?? null) ?? $tenant->city;
            $fields = $import->formData($source, $city->timezone);
            $warning = null;
            if (! empty($source['cover']['url'])) {
                try {
                    $photo = $import->photo($source['cover']['url']);
                } catch (\Throwable $error) {
                    report($error);
                    $warning = __('facebook_import.photo_failed');
                }
            }
            $this->form->fill(array_replace($this->data ?? [], $fields, ['city_id' => $city->id]));
            if ($photo !== null) {
                $this->data['poster'] = [(string) Str::uuid() => $photo];
            }
            $this->facebookImport = $source;
            Notification::make()->success()->title(__('facebook_import.loaded'))->body($warning ?? __('facebook_import.organizer_review'))->send();
        } catch (\Throwable $error) {
            $photo?->delete();
            report($error);
            Notification::make()->danger()->title(__('facebook_import.failed'))->body(__('facebook_import.failed_hint'))->send();
        }
    }

    /** Autore, organizzazione, bozza e provenienza: la regola sta in `OrganizerEventEditor`, la stessa dell'app. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        abort_unless($tenant instanceof Organizer && $user instanceof User, 403);

        return app(OrganizerEventEditor::class)->creationAttributes($tenant, $user, $data, $this->facebookImport);
    }

    protected function afterCreate(): void
    {
        $event = $this->getRecord();
        if (! $event instanceof Event) {
            throw new \LogicException('Expected event');
        }
        app(OrganizerEventEditor::class)->createFirstDate($event, $this->data['starts_at'] ?? null, $this->data['ends_at'] ?? null);
    }

    protected function getRedirectUrl(): string
    {
        return static::$resource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
