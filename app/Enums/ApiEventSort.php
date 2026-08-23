<?php

declare(strict_types=1);

namespace App\Enums;

use App\Queries\EventOccurrenceQuery;

/**
 * L'ordinamento di `GET /v1/events` (§13.2): `start|distance|popular|relevance`.
 *
 * È un enum distinto da `EventSort` perché i due vocabolari sono due
 * contratti diversi — il sito espone `sort=time`, l'API `sort=start` — e un
 * alias fra i due sarebbe una stringa magica in mezzo. Quello che i due
 * enum **non** duplicano è la logica: entrambi si limitano a dire quale
 * metodo di `EventOccurrenceQuery` va chiamato (§8).
 */
enum ApiEventSort: string
{
    case Start = 'start';
    case Distance = 'distance';
    case Popular = 'popular';
    case Relevance = 'relevance';

    /**
     * `Start` non chiama nulla: la cronologia è già l'ordinamento predefinito
     * del motore, e ridichiararlo significherebbe scriverlo due volte.
     */
    public function applyTo(EventOccurrenceQuery $query): EventOccurrenceQuery
    {
        return match ($this) {
            self::Start => $query,
            self::Distance => $query->orderByDistance(),
            self::Popular => $query->orderByPopularity(),
            self::Relevance => $query->orderByRelevance(),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
