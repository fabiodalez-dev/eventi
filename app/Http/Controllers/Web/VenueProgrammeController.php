<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\Venue;
use App\Services\Events\VenueProgramme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/locali/{slug}/programma.pdf`: il foglio «questa settimana da noi».
 *
 * La locandina del singolo evento esisteva; mancava quella da appendere in
 * bacheca con tutte le date della settimana. È pubblica come la scheda del
 * locale: chiunque può stamparla, e il locale la trova anche dal pannello.
 * `?settimana=YYYY-MM-DD` sceglie un lunedì diverso da quello corrente.
 */
final class VenueProgrammeController extends Controller
{
    use InteractsWithCity;

    public function __invoke(Request $request, string $slug, VenueProgramme $programme): Response
    {
        $city = $this->city();
        $venue = Venue::query()->approved()->where('slug', $slug)->firstOrFail();
        $requested = $request->query('settimana');
        $monday = $programme->monday($city, is_string($requested) ? $requested : null);
        $occurrences = $programme->occurrences($venue, $city, $monday);

        return response($programme->pdf($venue, $city, $monday, $occurrences), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$programme->filename($venue, $monday).'"',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
