<?php

declare(strict_types=1);

namespace App\Queries;

/**
 * Criterio di ordinamento di `EventOccurrenceQuery`. Non è uno stato di
 * dominio e non compare mai nell'interfaccia: non ha `label()`.
 */
enum OccurrenceOrdering
{
    /** Cronologico puro: è il comportamento predefinito di liste e API. */
    case Chronological;

    /** Ordinamento di "in corso" e "inizia tra poco" (§8.5). */
    case Live;

    /** In evidenza e punteggio redazionale prima della cronologia. */
    case Relevance;
}
