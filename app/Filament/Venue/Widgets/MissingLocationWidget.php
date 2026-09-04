<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\City;
use App\Models\Venue;
use Filament\Widgets\Widget;

/**
 * «Non siete ancora sulla mappa»: l'avviso che compare finché il locale non
 * ha messo il proprio punto.
 *
 * **Perché serve un avviso e non basta il campo.** Un locale nato da una
 * richiesta di iscrizione riceve le coordinate del centro città — non c'è
 * modo di ricavarle da un modulo che chiede solo un indirizzo scritto a mano,
 * e lasciare la scheda senza punto la escluderebbe dalla mappa. Il risultato
 * è che il locale *sembra* posizionato: nessun campo vuoto, nessun errore,
 * un segnaposto sulla mappa. Solo che è in piazza insieme a tutti gli altri.
 *
 * Nessuno va a controllare una cosa che sembra a posto. Per questo l'avviso
 * sta sulla prima pagina che si apre entrando, e sparisce da solo appena il
 * segnaposto viene spostato.
 *
 * **Come si riconosce il «non impostato».** Il punto coincide con il centro
 * della città alla settima cifra decimale — la precisione con cui la colonna
 * salva. Chi ha spostato il segnaposto anche di pochi metri non coincide più,
 * e non viene disturbato.
 */
class MissingLocationWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.missing-location';

    public static function canView(): bool
    {
        return self::sulCentroCitta(CurrentVenue::get());
    }

    public function getVenue(): Venue
    {
        return CurrentVenue::get();
    }

    private static function sulCentroCitta(Venue $venue): bool
    {
        $citta = $venue->city ?? City::query()->find($venue->city_id);

        if ($citta === null) {
            return false;
        }

        return (float) $venue->lat === (float) $citta->center_lat
            && (float) $venue->lng === (float) $citta->center_lng;
    }
}
