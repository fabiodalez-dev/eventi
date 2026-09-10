<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\SocialFormat;
use App\Enums\SocialPublicationStatus;
use App\Jobs\Social\PublishSocial;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Queries\EventOccurrenceQuery;
use App\Services\Social\SocialCatalog;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudio;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

trait InteractsWithSocialStudio
{
    #[Url]
    public ?int $event = null;

    public int $cityId = 0;

    public string $date = '';

    public string $format = 'portrait';

    public bool $monochrome = true;

    public bool $showAddress = true;

    public bool $showPrice = true;

    public string $imageFit = 'contain';

    public string $graphicTitle = '';

    /** @var list<int|string> */
    public array $selected = [];

    #[Locked]
    public ?string $batchId = null;

    public function mount(): void
    {
        $venue = $this->socialVenue();
        $city = $venue !== null ? $venue->city : (City::query()->where('is_active', true)->first() ?? City::query()->firstOrFail());
        $this->cityId = $city->id;
        $this->date = EventOccurrenceQuery::for($city)->currentBusinessDate();
        if ($this->event !== null) {
            $event = Event::findOrFail($this->event);
            Gate::authorize('update', $event);
            abort_if($this->socialVenue() !== null && $event->venue_id !== $this->socialVenue()->id, 403);
            $this->cityId = $event->city_id;
            $next = EventOccurrenceQuery::managementFor($event)->upcoming()->get()->first();
            if ($next !== null) {
                $this->date = $next->business_date->format('Y-m-d');
            }
        }
        $this->selectAll();
    }

    public function getTitle(): string
    {
        return __('social.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('social.title');
    }

    public function socialCity(): City
    {
        $venue = $this->socialVenue();

        return $venue !== null ? $venue->city : City::findOrFail($this->cityId);
    }

    /** @return Collection<int, EventOccurrence> */
    public function dates(): Collection
    {
        Validator::make(['date' => $this->date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($this->event !== null) {
            Gate::authorize('update', Event::findOrFail($this->event));
        }

        return app(SocialCatalog::class)->events($this->socialCity(), $this->date, $this->socialVenue()?->id, $this->event);
    }

    public function selectAll(): void
    {
        $this->selected = $this->dates()->pluck('id')->all();
        $this->batchId = null;
    }

    public function updatedDate(): void
    {
        $this->selectAll();
    }

    public function updatedCityId(): void
    {
        $this->event = null;
        $this->selectAll();
    }

    public function clearEvent(): void
    {
        $this->event = null;
        $this->graphicTitle = '';
        $this->selectAll();
    }

    public function generate(): void
    {
        $this->validate(['format' => ['required', 'in:portrait,square,story'], 'imageFit' => ['required', 'in:cover,contain'], 'selected' => ['required', 'array', 'min:1', 'max:100'], 'selected.*' => ['integer'], 'graphicTitle' => ['string', 'max:300']]);
        $dates = $this->dates()->whereIn('id', $this->selected)->values();
        abort_unless($dates->count() === count(array_unique($this->selected)), 403);
        if ($dates->count() !== 1 && $this->graphicTitle !== '') {
            $this->addError('graphicTitle', __('social.title_single'));

            return;
        }
        try {
            $batch = app(SocialStudio::class)->generate($this->socialCity(), $this->date, $dates, SocialFormat::from($this->format), ['monochrome' => $this->monochrome, 'show_address' => $this->showAddress, 'show_price' => $this->showPrice, 'image_fit' => $this->imageFit, 'title' => $this->graphicTitle], auth()->user(), $this->socialVenue()?->id);
            $this->batchId = $batch->id;
            Notification::make()->title(__('social.ready'))->success()->send();
        } catch (\RuntimeException $e) {
            $this->addError('generate', $e->getMessage());
        }
    }

    public function batch(): ?SocialBatch
    {
        if ($this->batchId === null) {
            return null;
        }
        $batch = SocialBatch::findOrFail($this->batchId);
        Gate::authorize('view', $batch);
        abort_if($this->socialVenue() !== null && $batch->venue_id !== $this->socialVenue()->id, 403);

        return $batch;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SocialBatch> */
    public function recentBatches(): \Illuminate\Database\Eloquent\Collection
    {
        return SocialBatch::where('city_id', $this->socialCity()->id)->where('venue_id', $this->socialVenue()?->id)->latest()->limit(12)->get();
    }

    public function openBatch(string $id): void
    {
        $batch = SocialBatch::findOrFail($id);
        Gate::authorize('view', $batch);
        abort_if($this->socialVenue() !== null && $batch->venue_id !== $this->socialVenue()->id, 403);
        $this->batchId = $id;
    }

    public string $scheduledAt = '';

    public function schedulePublication(): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $this->validate(['scheduledAt' => ['required', 'date_format:Y-m-d\\TH:i']]);
        $when = CarbonImmutable::parse($this->scheduledAt, $this->socialCity()->timezone)->utc();
        if ($when->lessThanOrEqualTo(now())) {
            $this->addError('scheduledAt', 'Scegli una data e un orario futuri.');

            return;
        }
        $batch = $this->batch();
        abort_if($batch === null, 404);
        if (SocialPublication::where('social_batch_id', $batch->id)->exists()) {
            $this->addError('scheduledAt', 'Questo contenuto è già nello storico. Modifica la programmazione da lì oppure prepara un nuovo contenuto.');

            return;
        }
        try {
            app(SocialPublisher::class)->enqueue($batch, scheduledAt: $when);
            Notification::make()->title('Pubblicazione programmata')->success()->send();
        } catch (\RuntimeException $e) {
            $this->addError('scheduledAt', $e->getMessage());
        }
    }

    public function cancelScheduledPublication(int $id): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['status' => SocialPublicationStatus::Cancelled->value]);
        Notification::make()->title('Programmazione annullata se non era già in esecuzione')->success()->send();
    }

    public function reschedulePublication(int $id): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $this->validate(['scheduledAt' => ['required', 'date_format:Y-m-d\\TH:i']]);
        $publication = SocialPublication::findOrFail($id);
        $batch = SocialBatch::findOrFail($publication->social_batch_id);
        $city = City::findOrFail($batch->city_id);
        $when = CarbonImmutable::parse($this->scheduledAt, $city->timezone)->utc();
        if ($when->lessThanOrEqualTo(now())) {
            $this->addError('scheduledAt', 'Scegli una data e un orario futuri.');

            return;
        }
        $changed = SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['scheduled_at' => $when]);
        if ($changed) {
            Notification::make()->title('Programmazione aggiornata')->success()->send();
        }
    }

    public function publish(): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $batch = $this->batch();
        abort_if($batch === null, 404);
        try {
            app(SocialPublisher::class)->enqueue($batch);
            Notification::make()->title(__('social.queued'))->success()->send();
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
    }

    public function retryPublication(int $id): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $publication = SocialPublication::findOrFail($id);
        abort_unless(in_array($publication->status, [SocialPublicationStatus::Failed, SocialPublicationStatus::Uncertain], true), 422);
        $batch = SocialBatch::findOrFail($publication->social_batch_id);
        try {
            app(SocialPublisher::class)->assertFresh($batch);
            $connection = SocialConnection::findOrFail($publication->social_connection_id);
            $changed = SocialPublication::whereKey($id)->where('status', $publication->status->value)->update(['status' => SocialPublicationStatus::Queued->value, 'error' => null, 'remote_ids' => json_encode(['page_id' => $connection->page_id, 'instagram_id' => $connection->instagram_id, 'telegram_chat_id' => $connection->telegram_chat_id], JSON_THROW_ON_ERROR)]);
            if ($changed) {
                PublishSocial::dispatch($id);
            }
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
    }
}
