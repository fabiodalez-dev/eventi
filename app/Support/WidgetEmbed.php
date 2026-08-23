<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Venue;

/**
 * Il widget incorporabile di §11.10: il riquadro che un locale mette sul
 * proprio sito per mostrare i propri prossimi eventi presi da qui.
 *
 * È un `iframe` e non uno `<script>` di proposito. Uno script incorporato
 * gira nel dominio di chi lo ospita, vede la sua pagina e i suoi cookie, e
 * chiede a quel sito di fidarsi di noi per sempre; un `iframe` è murato nel
 * proprio contesto e non può fare nulla alla pagina che lo contiene. Per lo
 * stesso motivo il riquadro non porta alcun tracciamento: è una vetrina, non
 * un sensore.
 */
final class WidgetEmbed
{
    /**
     * Quante date mostra il riquadro se nessuno dice altro.
     */
    public const DEFAULT_LIMIT = 5;

    /**
     * Oltre questo numero il riquadro diventa una pagina, e per quella esiste
     * la scheda del locale.
     */
    public const MAX_LIMIT = 12;

    /**
     * Altezza suggerita: quella in cui cinque righe stanno senza scorrere.
     */
    private const HEIGHT_PER_EVENT = 76;

    private const HEIGHT_CHROME = 116;

    public static function url(Venue $venue, int $limit = self::DEFAULT_LIMIT): string
    {
        return route('widget.show', array_filter([
            'venue' => $venue->slug,
            'limite' => $limit === self::DEFAULT_LIMIT ? null : $limit,
        ]));
    }

    /**
     * Il codice da copiare e incollare. `loading="lazy"` perché il riquadro sta
     * quasi sempre in fondo a una pagina, e `title` perché senza un nome
     * l'iframe è un buco muto per chi naviga con uno screen reader.
     */
    public static function snippet(Venue $venue, int $limit = self::DEFAULT_LIMIT): string
    {
        return sprintf(
            '<iframe src="%s" title="%s" width="100%%" height="%d" style="border:0;max-width:100%%" loading="lazy"></iframe>',
            e(self::url($venue, $limit)),
            e(__('venues.widget.frame_title', ['venue' => $venue->name])),
            self::height($limit),
        );
    }

    public static function height(int $limit): int
    {
        return self::HEIGHT_CHROME + self::HEIGHT_PER_EVENT * max(min($limit, self::MAX_LIMIT), 1);
    }

    /**
     * Il numero di date chiesto, riportato dentro i limiti ammessi.
     */
    public static function limit(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return self::DEFAULT_LIMIT;
        }

        return max(min((int) $requested, self::MAX_LIMIT), 1);
    }
}
