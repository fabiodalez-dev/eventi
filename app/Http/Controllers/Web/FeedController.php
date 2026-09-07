<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\EventFilterRequest;
use App\Services\Calendar\OccurrenceCalendar;
use App\Services\Feeds\EventFeed;
use Illuminate\Http\Response;

/**
 * I feed di §11.10: il calendario sottoscrivibile e l'RSS.
 *
 * Sono «leva di crescita, non optional»: chiunque deve poter mettere il
 * calendario della città nel proprio telefono e riceverlo aggiornato senza
 * tornare qui. Entrambi accettano gli stessi filtri della lista, quindi
 * esistono anche il calendario della sola categoria "musica", quello di un tag
 * e quello di un singolo locale — che è esattamente ciò che un locale vuole
 * mostrare ai propri clienti.
 */
final class FeedController extends Controller
{
    use InteractsWithCity;

    public function __construct(
        private readonly EventFeed $feed,
        private readonly OccurrenceCalendar $calendar,
    ) {}

    public function calendar(EventFilterRequest $request): Response
    {
        $city = $this->city();
        $filters = $request->filters();
        $name = $this->feed->name($city, $filters);

        $body = $this->calendar->feed(
            $this->feed->occurrences($city, $filters, days: $request->integer('days', config()->integer('feeds.days_ahead'))),
            $name,
            __('feeds.calendar.description', ['name' => $name, 'app' => config()->string('app.name')]),
        );

        /*
         * `inline` e non `attachment`: il file va aperto dal calendario di
         * sistema, non salvato in una cartella di scarichi dove nessuno lo
         * ritroverà.
         */
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="eventi.ics"',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    public function rss(EventFilterRequest $request): Response
    {
        $city = $this->city();
        $filters = $request->filters();

        /* La dichiarazione XML dev'essere il primo carattere del documento:
           i lettori di feed rifiutano un documento che comincia con una riga
           vuota, e una riga vuota è ciò che lascia dietro di sé un blocco
           `@php` di Blade. */
        $body = view('feeds.rss', [
            'city' => $city,
            'title' => $this->feed->name($city, $filters),
            'link' => route('events.index', $filters->toQueryString()),
            'self' => url()->full(),
            'occurrences' => $this->feed->occurrences($city, $filters, config()->integer('feeds.rss_items')),
        ])->render();

        return response(ltrim($body), 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
