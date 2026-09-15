<?php

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Filament\Organizer\Resources\Events\EventResource;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Services\Import\FacebookEventImport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\RateLimiter;
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
        abort_unless($tenant instanceof Organizer && auth()->user()?->can('create', [Event::class, $tenant]), 403);
        $key = 'facebook-import:'.auth()->id();
        foreach ([[$key, 3, 60, 'minute_limit'], [$key.':daily', 30, 86400, 'daily_limit']] as [$bucket, $limit, $seconds, $message]) {
            if (RateLimiter::tooManyAttempts($bucket, $limit)) {
                Notification::make()->warning()->title(__('facebook_import.'.$message))->send();

                return;
            }
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key.':daily', 86400);
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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['organizer_id'] = Filament::getTenant()->getKey();
        $data['status'] = 'draft';
        $data['source'] = 'manual';
        if ($this->facebookImport !== []) {
            $data['source_metadata'] = ['facebook' => $this->facebookImport];
            $data['source_ref'] = 'facebook:'.$this->facebookImport['id'];
            $data['external_links'] = app(FacebookEventImport::class)->formData($this->facebookImport, 'UTC')['external_links'];
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! empty($this->data['starts_at'])) {
            $event = $this->getRecord();
            if (! $event instanceof Event) {
                throw new \LogicException('Expected event');
            }
            $event->occurrences()->create([
                'starts_at' => CarbonImmutable::parse($this->data['starts_at'], $event->city->timezone)->utc(),
                'ends_at' => ! empty($this->data['ends_at']) ? CarbonImmutable::parse($this->data['ends_at'], $event->city->timezone)->utc() : null,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::$resource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
