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

    /** Distanza crescente: ha senso solo dopo `near()` (§13.2, `sort=distance`). */
    case Distance;

    /** Dalla data più recente alla più vecchia: è l'ordine di un archivio (§11.9). */
    case ReverseChronological;

    /** Più salvati e più visti prima della cronologia (§13.2, `sort=popular`). */
    case Popular;
}
