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
     * Le opzioni passate a `vkurko/calendar`.
     *
     * I nomi dei giorni arrivano gia' in italiano — la libreria li chiede al
     * browser a partire dalla lingua dell'applicazione — ma le etichette dei
     * pulsanti no: sono testo scritto nel codice, e «today» restava in
     * inglese in mezzo a «lun mar mer». Una parola sola, che pero' e' l'unica
     * inglese di tutta la schermata: si nota proprio perche' e' sola.
     *
     * @var array<string, mixed>
     */
    protected array $options = [
        'buttonText' => [
            'today' => 'Oggi',
            'dayGridMonth' => 'Mese',
            'timeGridWeek' => 'Settimana',
            'timeGridDay' => 'Giorno',
            'listWeek' => 'Elenco',
        ],

        /*
         * **Quattro viste, non le sedici che la libreria offre.**
         *
         * Ognuna risponde a una domanda diversa, e chi programma le fa tutte
         * e quattro:
         *
         * - **mese**: «com'è messo settembre» — i buchi nel programma si
         *   vedono solo qui, e sono l'informazione che nessuna lista dà;
         * - **settimana** con le ore: «cosa si sovrappone» — tre concerti lo
         *   stesso sabato alle 21 sono un problema che sulla griglia mensile
         *   sembra una settimana piena;
         * - **giorno**: una serata affollata, letta per intero;
         * - **elenco**: gli eventi in fila con data e ora. È l'unica leggibile
         *   su un telefono, dove una griglia mensile diventa trentun caselle
         *   da un centimetro.
         *
         * Le altre dodici — anno, timeline, risorse — servono a chi prenota
         * sale e attrezzature, non a chi guarda cosa succede in città. Un
         * menu di sedici voci è rumore: si sceglie fra quattro, non si cerca
         * fra sedici.
         */
        'headerToolbar' => [
            'start' => 'title',
            'center' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            'end' => 'today prev,next',
        ],

        /*
         * **La giornata comincia alle 6 del mattino e finisce alle 6 del
         * mattino dopo.**
         *
         * Ventiquattro ore piene, ma spostate: nessun evento sparisce e la
         * notte finisce in fondo alla colonna del giorno a cui appartiene
         * davvero. Un after-hours delle 2 di sabato è la fine del venerdì
         * sera per chi c'era, e con la giornata che comincia a mezzanotte
         * compare invece in cima al sabato, staccato dalla serata di cui fa
         * parte.
         *
         * `scrollTime` non basta: la libreria lo applica creando la vista, e
         * il calendario nasce in vista mese — passando a «Settimana» si
         * apriva a mezzanotte su sei ore vuote. Spostare la finestra risolve
         * il problema invece di rincorrerlo, e in più rende il tabellone più
         * fedele a come una serata viene vissuta.
         */
        'slotMinTime' => '06:00:00',
        'slotMaxTime' => '30:00:00',

        /* La settimana comincia di lunedi', come in Italia: `0` sarebbe
           domenica, ed è il valore predefinito della libreria. */
        'firstDay' => 1,

        /*
         * Nella vista mese, quando gli eventi non entrano nella cella compare
         * «+N altri» invece di allungarla. Senza, un sabato con dodici eventi
         * rende la sua riga alta il triplo delle altre e la griglia smette di
         * essere una griglia.
         *
         * **Booleano e non un numero**: qui il limite non è «quanti», è
         * «quanti ce ne stanno» — la libreria lo calcola dall'altezza
         * disponibile. Passare `4` non è un errore che si vede: viene letto
         * come vero e si comporta uguale, ma dichiara un limite che nessuno
         * applica.
         */
        'dayMaxEvents' => true,
    ];

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
        $locale = $occorrenza->effectiveVenue()?->name;

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
