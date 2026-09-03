<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\EventStatus;
use App\Models\EventOccurrence;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Collection;

/**
 * Il calendario della redazione: cosa c'è in città, giorno per giorno.
 *
 * **Perché serviva.** Il pannello mostrava gli eventi come elenco ordinato per
 * data, e un elenco risponde a «quando è questo evento» ma non a «cosa
 * succede giovedì» — che è la domanda di chi programma. I vuoti si vedono
 * solo su una griglia: tre concerti lo stesso sabato e il mercoledì deserto
 * sono un'informazione che nessuna riga trasmette.
 *
 * **Le occorrenze, non gli eventi.** Una rassegna di dieci serate è un evento
 * solo con dieci date: su un calendario deve comparire dieci volte, altrimenti
 * il giorno in cui suona davvero resta vuoto. È la stessa distinzione su cui
 * è costruito tutto il modello del progetto.
 *
 * **Il colore dice lo stato, non la categoria.** Chi apre questa vista sta
 * moderando: gli serve vedere subito cosa è ancora in attesa e cosa è già
 * pubblico. Le categorie hanno già il loro colore nelle liste pubbliche.
 */
class EventsCalendarWidget extends CalendarWidget
{
    protected bool $eventClickEnabled = true;

    /**
     * @return Collection<int, CalendarEvent>
     */
    protected function getEvents(FetchInfo $info): Collection
    {
        return EventOccurrence::query()
            ->with(['event.venue', 'event.city'])
            /*
             * Solo la finestra che il calendario sta mostrando. Senza questo
             * filtro si caricherebbero tutte le occorrenze mai generate — e
             * `occurrences:generate` ne produce dodici mesi per ogni
             * ricorrenza: su qualche decina di rassegne sono migliaia di righe
             * per disegnare una settimana.
             */
            ->whereBetween('starts_at', [$info->start, $info->end])
            ->whereHas('event', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('starts_at')
            ->get()
            ->map(function (EventOccurrence $occorrenza): CalendarEvent {
                $evento = $occorrenza->event;
                $fuso = $evento->city->timezone;

                return CalendarEvent::make()
                    ->title($this->titolo($occorrenza))
                    /* Nel fuso della città: un concerto delle 21 mostrato
                       alle 19 perché il server ragiona in UTC è un errore
                       che si nota solo quando qualcuno arriva in ritardo. */
                    ->start($occorrenza->starts_at->setTimezone($fuso))
                    ->end($occorrenza->effective_ends_at->setTimezone($fuso))
                    ->backgroundColor($this->colore($evento->status))
                    ->url(route('filament.admin.resources.events.edit', $evento), '_self');
            });
    }

    private function titolo(EventOccurrence $occorrenza): string
    {
        $locale = $occorrenza->event->venue?->name;

        /* Il locale nel titolo e non in un riquadro a parte: su una cella di
           calendario larga cento pixel non c'è spazio per due righe, e
           «dove» è la seconda cosa che si cerca dopo «cosa». */
        return $locale === null
            ? $occorrenza->event->title
            : $occorrenza->event->title.' · '.$locale;
    }

    private function colore(EventStatus $stato): string
    {
        return match ($stato) {
            EventStatus::Published => '#7a9900',
            EventStatus::Pending => '#b45309',
            EventStatus::Draft => '#6b6b66',
            EventStatus::Rejected => '#b91c1c',
            EventStatus::Archived => '#a1a1aa',
            EventStatus::Cancelled => '#7f1d1d',
        };
    }
}
