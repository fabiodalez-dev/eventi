<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipStatus;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\GrantCampaigns;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

/** Campagne e storico del locale. La scelta degli eventi passa sempre da GrantCampaigns. */
class Sponsorships extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'sponsorizzazioni';

    protected string $view = 'filament.venue.pages.sponsorships';

    public static function getNavigationLabel(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getTitle(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getSubheading(): ?string
    {
        return __('promotions.venue_lead');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('choose')->label(__('promotions.choose'))->schema([
            Select::make('grant')->label(__('promotions.grant'))->required()->options(fn () => SponsorshipGrant::active()->where('venue_id', CurrentVenue::get()->id)
                ->where('mode', PromotionMode::Selected)->get()->mapWithKeys(fn ($g) => [$g->id => $g->placement->label().' · '.$g->ends_at->timezone('Europe/Rome')->format('d/m/Y')])->all()),
            Select::make('event')->label(__('promotions.event'))->required()->searchable()->options(fn () => Event::where('venue_id', CurrentVenue::get()->id)->orderBy('title')->pluck('title', 'id')),
        ])->visible(fn (): bool => SponsorshipGrant::active()->where('venue_id', CurrentVenue::get()->id)->where('mode', PromotionMode::Selected)->exists())
            ->action(function (array $data): void {
                $grant = SponsorshipGrant::where('venue_id', CurrentVenue::get()->id)->findOrFail($data['grant']);
                $event = Event::where('venue_id', CurrentVenue::get()->id)->findOrFail($data['event']);
                app(GrantCampaigns::class)->choose(auth()->user(), $grant, $event);
                Notification::make()->title(__('promotions.success'))->success()->send();
            })];
    }

    /** @return Collection<int, SponsorshipGrant> */
    public function grants(): Collection
    {
        return SponsorshipGrant::where('venue_id', CurrentVenue::get()->id)->orderByDesc('ends_at')->get();
    }

    public function stop(int $id): void
    {
        $campaign = Sponsorship::whereHas('event', fn ($q) => $q->where('venue_id', CurrentVenue::get()->id))
            ->whereHas('grant', fn ($q) => $q->where('mode', PromotionMode::Selected)->where('venue_id', CurrentVenue::get()->id))->findOrFail($id);
        Gate::authorize('update', $campaign->event);
        $campaign->update(['status' => SponsorshipStatus::Paused]);
        Notification::make()->title(__('promotions.stopped'))->success()->send();
    }

    /** @return LengthAwarePaginator<int, Sponsorship> */
    public function sponsorships(): LengthAwarePaginator
    {
        $venue = CurrentVenue::get();

        return Sponsorship::query()
            ->whereHas('event', fn ($event) => $event->where('venue_id', $venue->getKey()))
            ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', $venue->id)))
            ->with(['event', 'grant'])
            ->orderByDesc('ends_at')
            ->paginate(25);
    }

    /** @return array{impressions: int, clicks: int} */
    public function totals(): array
    {
        $totals = Sponsorship::whereHas('event', fn ($q) => $q->where('venue_id', CurrentVenue::get()->id))
            ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', CurrentVenue::get()->id)))
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')->first();

        return ['impressions' => (int) $totals?->impressions, 'clicks' => (int) $totals?->clicks];
    }
}
