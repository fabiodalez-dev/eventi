<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Venue;
use App\Support\Redirect\RegistroRedirect;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * `venues.location` è una colonna calcolata, non un campo da compilare.
 *
 * Il punto geometrico serve solo all'indice spaziale: le coordinate leggibili
 * restano `lat` e `lng`, ed è da quelle che va derivato — altrimenti
 * basterebbe correggere una latitudine dal pannello per lasciare il locale
 * geograficamente fermo dov'era, con la mappa e il "vicino a me" che
 * continuano a rispondere sul vecchio punto senza che nulla segnali l'errore.
 *
 * Il costruttore della libreria vuole la **latitudine per prima**
 * (`new Point($lat, $lng, 0)`) mentre nel database finisce `POINT(lng lat)`
 * con SRID 0: è `Point::getWktData()` a invertire, e vale la trappola
 * documentata in §4 delle convenzioni e in `docs/SCHEMA.md` §3.2.
 */
class VenueObserver
{
    public function saving(Venue $venue): void
    {
        if (! $venue->exists) {
            $venue->location = $this->pointFor($venue);

            return;
        }

        if ($venue->isDirty(['lat', 'lng'])) {
            $venue->location = $this->pointFor($venue);
        }
    }

    /**
     * Lo slug di un locale che cambia lascia dietro di sé l'indirizzo stampato
     * sui volantini e quello incorporato in ogni widget. `city_id` è nullo
     * perché lo slug di un locale è unico in tutto il sistema (D12): la stessa
     * riga vale sia per `/locali/x` sia per `/padova/locali/x`.
     */
    public function updated(Venue $venue): void
    {
        if (! $venue->wasChanged('slug')) {
            return;
        }

        app(RegistroRedirect::class)->registra(
            null,
            '/locali/'.$venue->getOriginal('slug'),
            '/locali/'.$venue->slug,
        );
    }

    private function pointFor(Venue $venue): Point
    {
        return new Point((float) $venue->lat, (float) $venue->lng, 0);
    }
}
