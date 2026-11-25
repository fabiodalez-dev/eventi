<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Venue;
use App\Services\Management\DashboardSummary;
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
 * e non viene disturbato. La domanda la fa `DashboardSummary::missingLocation`,
 * la stessa del riepilogo dell'app.
 */
class MissingLocationWidget extends Widget
{
    protected static ?int $sort = 0;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.missing-location';

    public static function canView(): bool
    {
        return app(DashboardSummary::class)->missingLocation(CurrentVenue::get());
    }

    public function getVenue(): Venue
    {
        return CurrentVenue::get();
    }
}
