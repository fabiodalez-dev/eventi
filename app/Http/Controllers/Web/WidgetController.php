<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Support\WidgetEmbed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Il widget incorporabile di §11.10.
 *
 * Una pagina sola, autonoma, senza intestazione né piè di pagina del sito:
 * sta dentro l'`iframe` che un locale incolla nel proprio sito e mostra le sue
 * prossime date. Costa poco e crea dipendenza reciproca — il locale ha
 * interesse a tenere aggiornati i dati qui, perché sono quelli che compaiono
 * sul suo sito.
 *
 * Le date le sceglie il motore temporale come ovunque (§8): un widget che
 * mostrasse serate già passate sarebbe peggio di nessun widget.
 */
final class WidgetController extends Controller
{
    public function __invoke(Request $request, string $venue): Response
    {
        $model = $this->findVisible($venue);
        $city = $model->city;

        abort_if($city === null, 404);

        $limit = WidgetEmbed::limit($request->query('limite'));

        $occurrences = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->atVenue($model)
            ->get()
            ->take($limit)
            ->load(['event.category', 'event.venue']);

        $body = view('widget.show', [
            'venue' => $model,
            'occurrences' => $occurrences,
            'limit' => $limit,
        ])->render();

        /*
         * Il riquadro esiste **per** essere incorniciato da altri: dichiararlo
         * apertamente è il modo di non dipendere dal fatto che nessuno abbia
         * impostato un `X-Frame-Options` restrittivo più a monte. Non porta
         * cookie né sessione, quindi non c'è nulla da rubargli.
         */
        return response($body, 200, [
            'Content-Security-Policy' => 'frame-ancestors *',
            'Cache-Control' => 'public, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function findVisible(string $slug): Venue
    {
        $venue = Venue::query()
            ->with('city')
            ->where('slug', $slug)
            ->where('status', VenueStatus::Approved)
            ->first();

        abort_if($venue === null, 404);

        return $venue;
    }
}
