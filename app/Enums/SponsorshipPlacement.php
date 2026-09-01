<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Dove una sponsorizzazione può comparire.
 *
 * Non è un elenco di posti liberi: ogni collocazione ha un tetto e una forma
 * propria, ed è per questo che è un tipo chiuso invece di una stringa. Aprire
 * una collocazione nuova significa decidere quante ne stanno, come si
 * dichiarano e dove finiscono nella pagina — cioè scrivere codice, non
 * aggiungere una riga a una tabella.
 */
enum SponsorshipPlacement: string
{
    /** Il riquadro grande in apertura della pagina iniziale. */
    case HomeHero = 'home_hero';

    /** Una card in mezzo alle sezioni della pagina iniziale. */
    case HomeCard = 'home_card';

    /** In cima ai risultati di una lista. */
    case ListTop = 'list_top';

    /** Nel foglio che si apre toccando un punto della mappa. */
    case MapSheet = 'map_sheet';

    public function label(): string
    {
        return __('enums.sponsorship_placement.'.$this->value);
    }

    /**
     * Quante sponsorizzazioni di questa collocazione possono comparire insieme
     * nella stessa pagina.
     *
     * **Il tetto è la ragione per cui questo metodo esiste.** Senza, basta
     * vendere sei campagne per trasformare la pagina iniziale in un cartellone
     * e il catalogo in un dettaglio; chi apre il sito per sapere cosa fare
     * stasera se ne accorge subito e non torna. Uno slot in apertura e una
     * card per sezione sono la densità oltre la quale il prodotto smette di
     * essere un catalogo.
     */
    public function limit(): int
    {
        return match ($this) {
            self::HomeHero => 1,
            self::HomeCard => 1,
            self::ListTop => 1,
            self::MapSheet => 1,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
