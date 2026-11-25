<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\SocialFormat;
use App\Enums\SocialPublicationStatus;
use App\Filament\Support\PanelDate;
use App\Jobs\Social\PublishSocial;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Social\SocialCatalog;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudioDesk;
use App\Support\RequestMemo;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Lo studio delle grafiche social, uguale nel pannello della redazione e in
 * quello del locale.
 *
 * La pagina si legge in tre passi: si scelgono le serate (periodo, ricerca,
 * piccolo calendario, elenco a card con la locandina), si decide formato e
 * stile, si scaricano le grafiche pronte. Un pacchetto di grafiche riguarda
 * un solo giorno (`SocialStudio::generate()` lavora su una data): scegliere
 * una card di un altro giorno sposta `$date` su quel giorno e preseleziona
 * quella serata; le card dello stesso giorno si aggiungono o si tolgono.
 */
trait InteractsWithSocialStudio
{
    /** I periodi dell'elenco, nell'ordine in cui compaiono. */
    public const PERIODS = SocialStudioDesk::PERIODS;

    /** Quante card si mostrano per volta. */
    public const PAGE_SIZE = SocialStudioDesk::PAGE_SIZE;

    #[Url]
    public ?int $event = null;

    public int $cityId = 0;

    public string $date = '';

    public string $period = 'next30';

    public string $from = '';

    public string $until = '';

    public string $search = '';

    /** Il mese del piccolo calendario, `Y-m`. */
    public string $month = '';

    public int $limit = self::PAGE_SIZE;

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

    public string $scheduledAt = '';

    public function mount(): void
    {
        $venue = $this->socialVenue();
        $city = $venue !== null ? $venue->city : (City::query()->where('is_active', true)->first() ?? City::query()->firstOrFail());
        $this->cityId = $city->id;
        if ($this->event !== null) {
            $event = $this->studioEvent();
            Gate::authorize('update', $event);
            abort_if($this->socialVenue() !== null && $event->venue_id !== $this->socialVenue()->id, 403);
            $this->cityId = $event->city_id;
        }
        $this->applyPeriod('next30');
        // Con l'evento la sua prossima data; se lì non c'è niente da preparare, la prima serata in arrivo.
        $this->date = app(SocialStudioDesk::class)->initialDate($this->socialCity(), $this->socialVenue()?->id, $this->event !== null ? $this->studioEvent() : null, $this->includesDemo());
        $this->month = substr($this->date, 0, 7);
        $this->selectAll();
    }

    public function getTitle(): string
    {
        return __('social.title');
    }

    public function getSubheading(): ?string
    {
        return __('social.lead');
    }

    public static function getNavigationLabel(): string
    {
        return __('social.title');
    }

    public function socialCity(): City
    {
        $venue = $this->socialVenue();

        return $venue !== null ? $venue->city : RequestMemo::remember('social-city:'.$this->cityId, fn (): City => City::findOrFail($this->cityId));
    }

    /**
     * L'evento della scheda, con il suo organizzatore, letto una volta per
     * richiesta: lo studio ricontrolla il permesso in più punti della stessa
     * pagina, e ogni volta lo rileggeva.
     */
    private function studioEvent(): Event
    {
        return RequestMemo::remember('social-studio-event:'.$this->event, fn (): Event => Event::with(['organizer', 'city'])->findOrFail($this->event));
    }

    /**
     * Gli eventi dimostrativi restano fuori dal carosello automatico e dallo
     * studio della redazione; nel pannello del locale compaiono, segnati, perché
     * è il locale stesso che li ha in programma.
     */
    public function includesDemo(): bool
    {
        return $this->socialVenue() !== null;
    }

    /** @return Collection<int, EventOccurrence> */
    public function dates(): Collection
    {
        Validator::make(['date' => $this->date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($this->event !== null) {
            Gate::authorize('update', $this->studioEvent());
        }

        return app(SocialCatalog::class)->events($this->socialCity(), $this->date, $this->socialVenue()?->id, $this->event, $this->includesDemo());
    }

    /**
     * Gli estremi del periodo dell'elenco. Un valore non valido (un campo data
     * svuotato) riporta al periodo predefinito invece di rompere la pagina; un
     * periodo scelto a mano resta entro un anno.
     *
     * @return array{0: string, 1: string}
     */
    public function periodRange(): array
    {
        if (! $this->isDay($this->from) || ! $this->isDay($this->until)) {
            $this->applyPeriod('next30');
        }

        return SocialStudioDesk::clampRange($this->from, $this->until);
    }

    /** @return Collection<int, EventOccurrence> */
    public function catalog(): Collection
    {
        if ($this->event !== null) {
            Gate::authorize('update', $this->studioEvent());
        }
        [$from, $until] = $this->periodRange();

        return app(SocialCatalog::class)->period($this->socialCity(), $from, $until, $this->socialVenue()?->id, $this->event, $this->includesDemo(), $this->searchTerm(), $this->limit);
    }

    public function catalogTotal(): int
    {
        [$from, $until] = $this->periodRange();

        return app(SocialCatalog::class)->total($this->socialCity(), $from, $until, $this->socialVenue()?->id, $this->event, $this->includesDemo(), $this->searchTerm());
    }

    /**
     * Il piccolo calendario del mese: sei settimane da lunedì, con il numero
     * di serate di ogni giorno.
     *
     * @return list<array{date: string, day: int, count: int, inMonth: bool, today: bool, selected: bool}>
     */
    public function calendarDays(): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = substr($this->date, 0, 7);
        }
        if ($this->event !== null) {
            Gate::authorize('update', $this->studioEvent());
        }

        return app(SocialStudioDesk::class)->calendar($this->socialCity(), $this->month, $this->date, $this->socialVenue()?->id, $this->event, $this->includesDemo(), $this->searchTerm());
    }

    /**
     * Il periodo dell'elenco in parole: «oggi», «da oggi a dom 23 nov»,
     * «il sab 22 nov», «dal 1 dic al 31 dic».
     */
    public function periodLabel(): string
    {
        [$from, $until] = $this->periodRange();
        $today = EventOccurrenceQuery::for($this->socialCity())->currentBusinessDate();

        return match (true) {
            $from === $until && $from === $today => __('pannelli-social.studio.today_only'),
            $from === $until => __('pannelli-social.studio.one_day', ['day' => PanelDate::day($from)]),
            $from === $today => __('pannelli-social.studio.from_today', ['day' => PanelDate::day($until)]),
            default => PanelDate::period($from, $until),
        };
    }

    public function monthLabel(): string
    {
        return ucfirst(CarbonImmutable::parse($this->month.'-01')->locale('it')->translatedFormat('F Y'));
    }

    public function dateLabel(): string
    {
        return ucfirst(CarbonImmutable::parse($this->date)->locale('it')->translatedFormat('l j F'));
    }

    public function setPeriod(string $period): void
    {
        abort_unless(in_array($period, self::PERIODS, true), 422);
        $this->applyPeriod($period);
        $this->month = substr($this->from, 0, 7);
    }

    public function updatedFrom(): void
    {
        $this->period = 'custom';
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedUntil(): void
    {
        $this->period = 'custom';
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 100);
        $this->limit = self::PAGE_SIZE;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE, SocialStudioDesk::MAX_CARDS);
    }

    public function shiftMonth(int $step): void
    {
        Validator::make(['month' => $this->month], ['month' => ['required', 'date_format:Y-m']])->validate();
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonths($step <=> 0)->format('Y-m');
    }

    /**
     * Un giorno del calendario: l'elenco mostra quel giorno e le sue serate
     * diventano la selezione.
     */
    public function pickDay(string $day): void
    {
        Validator::make(['day' => $day], ['day' => ['required', 'date_format:Y-m-d']])->validate();
        $this->period = 'custom';
        $this->from = $day;
        $this->until = $day;
        $this->limit = self::PAGE_SIZE;
        $this->date = $day;
        $this->month = substr($day, 0, 7);
        $this->selectAll();
    }

    /**
     * Una card dell'elenco. Se è di un altro giorno, quel giorno diventa la
     * data delle grafiche e la serata è la sola selezionata; se è dello
     * stesso giorno, entra o esce dalla selezione.
     */
    public function pick(int $occurrence): void
    {
        $date = EventOccurrence::query()->findOrFail($occurrence);
        $day = $date->business_date->format('Y-m-d');
        abort_unless(app(SocialCatalog::class)->events($this->socialCity(), $day, $this->socialVenue()?->id, $this->event, $this->includesDemo())->contains('id', $date->id), 403);
        $this->batchId = null;
        if ($day !== $this->date) {
            $this->date = $day;
            $this->selected = [$date->id];

            return;
        }
        $selected = array_map('intval', $this->selected);
        $this->selected = in_array($date->id, $selected, true)
            ? array_values(array_diff($selected, [$date->id]))
            : [...$selected, $date->id];
    }

    /** @param  list<int|string>  $selected */
    public function isSelected(EventOccurrence $date, array $selected): bool
    {
        return $date->business_date->format('Y-m-d') === $this->date && in_array($date->id, array_map('intval', $selected), true);
    }

    public function selectAll(): void
    {
        $this->selected = $this->dates()->pluck('id')->all();
        $this->batchId = null;
    }

    public function updatedDate(): void
    {
        $this->month = $this->isDay($this->date) ? substr($this->date, 0, 7) : $this->month;
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
        $this->validate(['format' => ['required', 'in:portrait,square,story'], 'imageFit' => ['required', 'in:cover,contain'], 'selected' => ['required', 'array', 'min:1', 'max:'.SocialStudioDesk::MAX_SELECTION], 'selected.*' => ['integer'], 'graphicTitle' => ['string', 'max:'.SocialStudioDesk::TITLE_MAX]]);
        Validator::make(['date' => $this->date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($this->event !== null) {
            Gate::authorize('update', $this->studioEvent());
        }
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        try {
            $batch = app(SocialStudioDesk::class)->prepare($this->socialCity(), $this->date, array_map('intval', $this->selected), SocialFormat::from($this->format), ['monochrome' => $this->monochrome, 'show_address' => $this->showAddress, 'show_price' => $this->showPrice, 'image_fit' => $this->imageFit, 'title' => $this->graphicTitle], $user, $this->socialVenue()?->id, $this->event, $this->includesDemo());
            $this->batchId = $batch->id;
            Notification::make()->title(__('social.ready'))->success()->send();
        } catch (ValidationException $e) {
            $this->addError('graphicTitle', (string) collect($e->errors())->flatten()->first());
        } catch (HttpExceptionInterface $e) {
            // Una serata di un altro giorno o di un altro locale resta un 403, come prima.
            throw $e;
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
        return app(SocialStudioDesk::class)->recent($this->socialCity(), $this->socialVenue()?->id);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SocialPublication> */
    public function publications(): \Illuminate\Database\Eloquent\Collection
    {
        abort_unless($this->socialVenue() === null, 403);

        $city = $this->socialCity()->id;

        // Anche le pubblicazioni il cui pacchetto è stato tolto dalla pulizia: la città la dice il collegamento con cui sono uscite.
        return SocialPublication::query()
            ->where(fn ($query) => $query->whereIn('social_batch_id', SocialBatch::where('city_id', $city)->select('id'))
                ->orWhere(fn ($orphan) => $orphan->whereNull('social_batch_id')->whereIn('social_connection_id', SocialConnection::where('city_id', $city)->select('id'))))
            ->latest()->limit(30)->get();
    }

    public function openBatch(string $id): void
    {
        $batch = SocialBatch::findOrFail($id);
        Gate::authorize('view', $batch);
        abort_if($this->socialVenue() !== null && $batch->venue_id !== $this->socialVenue()->id, 403);
        $this->batchId = $id;
    }

    public function schedulePublication(): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $this->validate(['scheduledAt' => ['required', 'date_format:Y-m-d\\TH:i']]);
        $when = CarbonImmutable::parse($this->scheduledAt, $this->socialCity()->timezone)->utc();
        if ($when->lessThanOrEqualTo(now())) {
            $this->addError('scheduledAt', __('social.schedule.future'));

            return;
        }
        $batch = $this->batch();
        abort_if($batch === null, 404);
        if (SocialPublication::where('social_batch_id', $batch->id)->exists()) {
            $this->addError('scheduledAt', __('social.schedule.already'));

            return;
        }
        try {
            app(SocialPublisher::class)->enqueue($batch, scheduledAt: $when);
            Notification::make()->title(__('social.schedule.done'))->success()->send();
        } catch (\RuntimeException $e) {
            $this->addError('scheduledAt', $e->getMessage());
        }
    }

    public function cancelScheduledPublication(int $id): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['status' => SocialPublicationStatus::Cancelled->value]);
        Notification::make()->title(__('social.schedule.cancelled'))->success()->send();
    }

    public function reschedulePublication(int $id): void
    {
        abort_unless($this->socialVenue() === null && auth()->user()?->hasAnyRole(['admin', 'super_admin']), 403);
        $this->validate(['scheduledAt' => ['required', 'date_format:Y-m-d\\TH:i']]);
        $publication = SocialPublication::findOrFail($id);
        // Il fuso è quello della città del collegamento, che resta anche se il pacchetto è stato tolto.
        $city = City::findOrFail(SocialConnection::findOrFail($publication->social_connection_id)->city_id);
        $when = CarbonImmutable::parse($this->scheduledAt, $city->timezone)->utc();
        if ($when->lessThanOrEqualTo(now())) {
            $this->addError('scheduledAt', __('social.schedule.future'));

            return;
        }
        $changed = SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['scheduled_at' => $when]);
        if ($changed) {
            Notification::make()->title(__('social.schedule.updated'))->success()->send();
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
        $batch = $publication->batch;
        if ($batch === null) {
            $this->addError('publish', __('social.studio.batch_gone'));

            return;
        }
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

    private function applyPeriod(string $period): void
    {
        $this->period = $period;
        $this->limit = self::PAGE_SIZE;
        [$this->from, $this->until] = SocialStudioDesk::periodBounds($this->socialCity(), $period, $this->from, $this->until);
    }

    private function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function searchTerm(): string
    {
        return trim(mb_substr($this->search, 0, 100));
    }
}
