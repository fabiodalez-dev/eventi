<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Str;

/**
 * Il titolo della scheda di un evento, entro la misura che un motore mostra.
 *
 * ## Perché non basta uno schema nel file di lingua
 *
 * `:event a :city, :date` è giusto finché il titolo dell'evento è corto e non
 * nomina la città. Misurato su otto schede vere, sette superavano i sessanta
 * caratteri — fino a novantanove — e una diceva «PARCO DELLA MUSICA - PADOVA a
 * Padova», perché la città veniva aggiunta a un titolo che la conteneva già.
 * Oltre quella misura Google tronca, e quello che perde è la coda: proprio la
 * parte che distingue una serata dall'altra.
 *
 * ## Cosa si accorcia, e cosa no
 *
 * Si accorcia **il titolo dell'evento**; città e data restano intere. Sono
 * loro a rendere il risultato pertinente a chi cerca «concerti padova
 * stasera», mentre la coda di un titolo lungo è quasi sempre rumore — sigle,
 * nomi di sala, ripetizioni. Sotto una certa soglia però il titolo smette di
 * dire qualcosa: se per far stare la data non restano almeno {@see MINIMUM}
 * caratteri, cade prima la data.
 *
 * ## Il suffisso del sito è compreso nel conto
 *
 * Il `<title>` che finisce nella pagina è `{titolo} — {nome del sito}`, e la
 * misura che conta è quella intera: il limite qui dentro tiene da parte
 * {@see SITE_SEPARATOR} più il nome del sito, che è configurabile e quindi non
 * si può dare per lungo sette caratteri. Se un giorno il layout cambiasse
 * separatore, questo conto sarebbe sbagliato di poco e in silenzio: è il
 * motivo per cui il separatore è dichiarato qui e il test della scheda misura
 * il titolo servito, non quello calcolato.
 */
final class PageTitle
{
    /**
     * Quanti caratteri mostra un motore prima di troncare, suffisso del sito
     * compreso. Sessanta è la soglia prudente: il limite vero è in pixel e
     * dipende dai caratteri usati, ma nessuna misura in caratteri lo prevede
     * esattamente.
     */
    public const LIMIT = 60;

    /**
     * Ciò che il layout mette fra il titolo e il nome del sito.
     */
    public const SITE_SEPARATOR = ' — ';

    /**
     * Sotto questa soglia il titolo dell'evento non dice più niente, e allora
     * conviene rinunciare alla data.
     */
    private const MINIMUM = 25;

    /**
     * Il titolo di una scheda evento: nome dell'evento, città quando non è già
     * nominata, data quando ce n'è una scelta.
     */
    public static function forEvent(string $event, string $city, ?string $date = null): string
    {
        $event = Str::of($event)->stripTags()->squish()->value();
        $city = Str::of($city)->squish()->value();

        $withCity = $city !== '' && ! self::mentions($event, $city) ? $city : null;
        $withDate = $date === null || $date === '' ? null : $date;

        $budget = self::LIMIT - mb_strlen(self::SITE_SEPARATOR.config()->string('app.name'));

        if (mb_strlen(self::assemble($event, $withCity, $withDate)) <= $budget) {
            return self::assemble($event, $withCity, $withDate);
        }

        /*
         * Prima rinuncia: la città. Costa più caratteri della data e dice meno
         * di lei — il sito è già quello di una città sola, e la scheda la
         * ripete nell'indirizzo, nel titolo visibile e nei dati strutturati.
         * Toglierla salva l'identità dell'evento, che nel risultato di una
         * ricerca è l'unica cosa non ricostruibile da altrove: «Bollicine
         * tributo Vasco Rossi live in…» dice ancora di chi si tratta,
         * «Bollicine tributo Vasco… a Padova» no.
         */
        if ($withCity !== null && mb_strlen(self::assemble($event, null, $withDate)) <= $budget) {
            return self::assemble($event, null, $withDate);
        }

        $room = $budget - ($withDate === null ? 0 : mb_strlen(self::withDate('', $withDate)));

        /*
         * Non resta spazio per un titolo leggibile — succede solo con un nome
         * del sito molto lungo, perché è quello a mangiare la misura. Allora
         * cade anche la data, che chi legge ritrova comunque nella pagina e
         * nei dati strutturati.
         */
        if ($room < self::MINIMUM) {
            $withDate = null;
            $room = $budget;
        }

        return self::assemble(self::shorten($event, max($room, self::MINIMUM)), null, $withDate);
    }

    /**
     * Mette insieme i pezzi che ci sono, nell'ordine deciso dal file di lingua.
     */
    private static function assemble(string $event, ?string $city, ?string $date): string
    {
        $title = $city === null ? $event : self::withCity($event, $city);

        return $date === null ? $title : self::withDate($title, $date);
    }

    /**
     * L'evento con la sua città, nella forma che decide il file di lingua.
     */
    private static function withCity(string $event, string $city): string
    {
        return __('seo.event_title', ['event' => $event, 'city' => $city]);
    }

    /**
     * Il titolo con la data, nella forma che decide il file di lingua.
     */
    private static function withDate(string $title, string $date): string
    {
        return __('seo.date_title', ['title' => $title, 'date' => $date]);
    }

    /**
     * Se il titolo nomina già la città, ripeterla è spreco di caratteri.
     * Il confronto ignora maiuscole e accenti: «PADOVA» e «Padova» sono la
     * stessa parola, e i titoli dei locali gridano spesso in maiuscolo.
     */
    private static function mentions(string $event, string $city): bool
    {
        return str_contains(
            Str::lower(Str::ascii($event)),
            Str::lower(Str::ascii($city)),
        );
    }

    /**
     * Taglia sull'ultima parola intera e chiude con i puntini di sospensione.
     *
     * La punteggiatura rimasta appesa viene tolta: «Anime in Plexiglass |…» si
     * legge come un errore di stampa, «Anime in Plexiglass…» no.
     */
    private static function shorten(string $event, int $room): string
    {
        $cut = Str::limit($event, max($room - 1, 1), '', preserveWords: true);
        $cut = rtrim($cut, " \t\n\r\0\x0B-–—|,;:·.•/\\");

        return ($cut === '' ? mb_substr($event, 0, max($room - 1, 1)) : $cut).'…';
    }
}
