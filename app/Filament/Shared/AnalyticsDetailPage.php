<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Support\EventSelect;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Support\RequestMemo;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Schemas\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/** @property-read Event|Venue|Organizer $subject */
abstract class AnalyticsDetailPage extends EventAnalyticsPage
{
    protected static ?string $slug = 'statistiche-condivisioni/{subjectType}/{subjectId}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    /** «Guarda un altro evento»: l'evento scelto, prima del passaggio. */
    public ?int $otherEvent = null;

    public function mount(?string $subjectType = null, ?int $subjectId = null): void
    {
        abort_unless(in_array($subjectType, ['event', 'venue', 'organizer'], true) && $subjectId > 0, 404);
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subject();
        // Carry only date/channel controls across subjects, never another entity's scope.
        $this->filters = array_intersect_key($this->filters, array_flip(['from', 'until', 'channel']));
        parent::mount();
    }

    public static function canAccess(): bool
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
        }
        $tenant = Filament::getTenant();

        return ($tenant instanceof Venue || $tenant instanceof Organizer) && auth()->user()?->canAccessTenant($tenant) === true;
    }

    #[Computed]
    public function subject(): Event|Venue|Organizer
    {
        /* Il soggetto si ricontrolla a ogni richiesta (anche dalle chiamate
           dirette in `mount()` e nei filtri dei rapporti), ma con una domanda
           sola per richiesta: chi guarda, pannello e locale fanno parte della
           chiave, perché decidono che cosa si può vedere. */
        $tenant = Filament::getTenant();
        $key = implode(':', ['analytics-subject', $this->subjectType, $this->subjectId, auth()->id(), Filament::getCurrentPanel()?->getId(), $tenant?->getMorphClass(), $tenant?->getKey()]);

        return RequestMemo::remember($key, function (): Event|Venue|Organizer {
            $service = app(EventAnalyticsDashboard::class);
            $query = match ($this->subjectType) {
                'event' => $service->events()->with('organizer'), 'venue' => $service->venues(), 'organizer' => $service->organizers(),
                default => abort(404),
            };

            return $query->with('city')->findOrFail($this->subjectId);
        });
    }

    public function getTitle(): string
    {
        $subject = $this->subject;

        return __('analytics-dashboard.detail_title', ['name' => $subject instanceof Event ? $subject->title : $subject->name]);
    }

    public function overviewUrl(): string
    {
        $class = match (Filament::getCurrentPanel()?->getId()) {
            'admin' => EventShareAnalytics::class,
            'venue' => \App\Filament\Venue\Pages\EventShareAnalytics::class,
            'organizer' => \App\Filament\Organizer\Pages\EventShareAnalytics::class,
            default => abort(403),
        };

        return $class::getUrl(parameters: ['filters' => array_intersect_key($this->filters, array_flip(['from', 'until', 'channel']))]);
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [$this->overviewUrl() => __('analytics-dashboard.title'), __('analytics-dashboard.'.$this->subjectType)];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        return $this->subjectType === 'event' ? EventAnalyticsNavigation::items($this->subjectId, true) : [];
    }

    /** Il selettore «Guarda un altro evento», solo nella pagina di un evento. */
    public function otherEventForm(Schema $schema): Schema
    {
        return $schema->components([
            EventSelect::make('otherEvent', fn () => app(EventAnalyticsDashboard::class)->events()->whereKeyNot($this->subjectId))
                ->label(__('pannelli-statistiche.subject.other_event'))
                ->helperText(__('pannelli-statistiche.subject.other_event_hint'))
                ->live()
                ->afterStateUpdated(fn (mixed $state) => $this->goToEvent($state)),
        ]);
    }

    /**
     * Passa a un altro evento tenendo periodo e canale. L'evento si cerca di
     * nuovo fra quelli che chi guarda può vedere: un numero scritto a mano
     * finisce in un 404.
     */
    public function goToEvent(mixed $eventId): void
    {
        if (blank($eventId)) {
            return;
        }
        $event = app(EventAnalyticsDashboard::class)->events()->findOrFail((int) $eventId);
        $this->redirect($this->detailUrl('event', (int) $event->getKey()), navigate: false);
    }

    protected function reportFilters(): array
    {
        // Reauthorize on every report/download and pin the route subject even if Livewire filters are forged.
        $this->subject();

        return array_replace(array_intersect_key($this->filters, array_flip(['from', 'until', 'channel'])), [$this->subjectType => $this->subjectId]);
    }

    protected function entityFilters(): array
    {
        return [];
    }
}
