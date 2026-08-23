<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le relazioni che `include=venue,tags,lineup` aggiunge alla risposta (§13.2).
 *
 * Il locale **ridotto** è sempre presente in ogni occorrenza, perché senza di
 * esso una card non si può disegnare (§13.2). `include=venue` chiede la
 * scheda completa del locale — indirizzo, coordinate, contatti — che serve
 * alla pagina di dettaglio e non alla lista.
 */
enum ApiInclude: string
{
    case Venue = 'venue';
    case Tags = 'tags';
    case Lineup = 'lineup';

    /**
     * Le relazioni Eloquent da precaricare per questa inclusione: senza,
     * una lista di cinquanta occorrenze farebbe cinquanta interrogazioni.
     *
     * @return list<string>
     */
    public function relations(): array
    {
        return match ($this) {
            self::Venue => ['event.venue'],
            self::Tags => ['event.tags'],
            self::Lineup => ['lineups'],
        };
    }

    /**
     * @param  array<int, self>  $includes
     * @return list<string>
     */
    public static function relationsFor(array $includes): array
    {
        $relations = [];

        foreach ($includes as $include) {
            foreach ($include->relations() as $relation) {
                if (! in_array($relation, $relations, true)) {
                    $relations[] = $relation;
                }
            }
        }

        return $relations;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
