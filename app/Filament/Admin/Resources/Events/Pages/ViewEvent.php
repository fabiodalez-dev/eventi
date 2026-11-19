<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Admin\Pages\EventAnalyticsDetail;
use App\Filament\Admin\Pages\Social;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Support\EventModeration;
use App\Models\Event;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

/**
 * La scheda di un evento in redazione, in sola lettura.
 *
 * Esiste per chi modera senza poter modificare: il moderatore apriva
 * l'evento da «In attesa» e riceveva un 403, perché l'unica pagina era il
 * modulo di modifica. Ora legge la scheda — nella stessa forma di quella dei
 * pannelli dei locali: locandina, stato, date nel fuso della città,
 * descrizione — e decide dall'intestazione (pubblica, rifiuta, annulla).
 * Nessun campo si compila da qui; chi può modificare trova «Modifica» nella
 * testata della scheda, accanto a statistiche, grafiche social e sito.
 *
 * @extends ViewRecord<Event>
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->eventRecord()->title;
    }

    public function infolist(Schema $schema): Schema
    {
        $event = $this->eventRecord();

        return $schema->columns(1)->components([
            View::make('filament.admin.events.overview')
                ->viewData([
                    'editUrl' => EventResource::canEdit($event) ? EventResource::getUrl('edit', ['record' => $event]) : null,
                    'links' => $this->links($event),
                ])
                ->columnSpanFull(),
            View::make('filament.admin.events.moderation-summary')
                ->viewData(['event' => $event])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Le pagine della redazione collegate a questo evento, già filtrate su di
     * lui, solo per chi può aprirle.
     *
     * @return list<array{key: string, label: string, url: string, icon: string, external: bool}>
     */
    private function links(Event $event): array
    {
        $links = [];

        if (EventAnalyticsDetail::canAccess()) {
            $links[] = ['key' => 'statistics', 'label' => __('manage.event_view.statistics'), 'url' => EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->getKey()]), 'icon' => 'heroicon-o-chart-bar', 'external' => false];
        }

        if (Social::canAccess()) {
            $links[] = ['key' => 'social', 'label' => __('admin.view_pages.social'), 'url' => Social::getUrl(['event' => $event->getKey()]), 'icon' => 'heroicon-o-photo', 'external' => false];
        }

        if ($event->status === EventStatus::Published && Route::has('events.show')) {
            $links[] = ['key' => 'site', 'label' => __('admin.actions.view_on_site'), 'url' => route('events.show', $event), 'icon' => 'heroicon-o-arrow-top-right-on-square', 'external' => true];
        }

        return $links;
    }

    /**
     * Le date si leggono nella scheda; la sezione per cambiarle sta nella
     * modifica, dove ogni riga chiede «solo questa o tutta la serie».
     */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...EventModeration::decisions(),
            EventModeration::sponsor(),
        ];
    }

    private function eventRecord(): Event
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return $event;
    }
}
