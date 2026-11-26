<?php

declare(strict_types=1);

namespace App\Support\Venues;

use App\Models\Venue;

/**
 * L'immagine che rappresenta un locale negli elenchi della redazione: la
 * copertina in miniatura, poi il logo. Il pannello ci mette il segnaposto
 * quando non c'è niente (`VenueResource::coverUrl`), l'app riceve `null` e
 * disegna il suo.
 */
final class VenueCover
{
    public static function url(Venue $venue): ?string
    {
        foreach (['cover', 'logo'] as $collection) {
            $media = $venue->getFirstMedia($collection);

            if ($media !== null) {
                return $media->hasGeneratedConversion('thumb') ? $media->getFullUrl('thumb') : $media->getFullUrl();
            }
        }

        return null;
    }
}
