<?php

declare(strict_types=1);

namespace App\Support\Seo;

use DateTimeInterface;
use Spatie\Sitemap\Tags\Sitemap;

/**
 * Una riga dell'indice `sitemap.xml` che sa dire se la propria data di
 * modifica è **vera**.
 *
 * Serve per un dettaglio del pacchetto che altrimenti fa danno: il tag
 * `Spatie\Sitemap\Tags\Sitemap` nasce con `lastModificationDate = now()`, e la
 * proprietà è tipata `Carbon`, quindi non esiste un valore che significhi «non
 * lo so». Emettere quel valore per difetto significa dichiarare a ogni
 * richiesta che tutte le sezioni sono cambiate in questo istante — cioè
 * insegnare al motore che la nostra `lastmod` non vuol dire niente, che è
 * peggio di non averla. È per questo che la vista pubblicata in
 * `resources/views/vendor/sitemap/` l'aveva tolta del tutto.
 *
 * Qui la data si accende solo quando qualcuno la imposta davvero: le sezioni
 * che sanno quando sono cambiate la dichiarano, le altre restano mute.
 */
final class SitemapSection extends Sitemap
{
    /**
     * Vero solo dopo una chiamata esplicita a `setLastModificationDate()`.
     */
    public bool $lastModificationKnown = false;

    public function setLastModificationDate(DateTimeInterface $lastModificationDate): static
    {
        $this->lastModificationKnown = true;

        return parent::setLastModificationDate($lastModificationDate);
    }

    /**
     * Resta una riga `<sitemap>`, non un tipo nuovo.
     *
     * `Tag::getType()` deriva il nome della vista dal nome della classe, e
     * l'indice del pacchetto fa `@include('sitemap::sitemapIndex/'.$tag->getType())`:
     * senza questa riga una sottoclasse va a cercare
     * `sitemapIndex/sitemapsection`, che non esiste, e la mappa risponde 500.
     * È il genere di accoppiamento che si scopre solo eseguendo.
     */
    public function getType(): string
    {
        return 'sitemap';
    }
}
