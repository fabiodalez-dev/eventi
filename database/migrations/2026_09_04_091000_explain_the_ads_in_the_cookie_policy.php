<?php

declare(strict_types=1);

use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * Riscrive l'apertura della Cookie Policy e le aggiunge la parte sugli
 * annunci.
 *
 * Il testo diceva «non usa cookie di profilazione, **non ha pubblicità** e non
 * condivide nulla con reti pubblicitarie». La terza cosa è ancora vera; le
 * prime due no, da quando ci sono le sponsorizzazioni e la scelta degli
 * annunci guarda le categorie degli eventi salvati.
 *
 * **La forma della correzione conta quanto la correzione.** Sarebbe stato
 * comodo togliere la frase e lasciar perdere: nessuno l'avrebbe notato. Invece
 * la nuova versione dice per esteso cosa succede e la chiama profilazione,
 * perché è quello che è — piccola, tutta interna a questo sito, ma un nome
 * sbagliato in un'informativa vale meno di nessun nome.
 *
 * Sostituisce **solo** il paragrafo che riconosce: se la redazione l'ha già
 * riscritto, la sua versione resta.
 */
return new class extends Migration
{
    private const VECCHIO = 'Questo sito **non usa cookie di profilazione, non ha pubblicità e non condivide nulla con
reti pubblicitarie.** Quello che segue è l\'elenco completo, senza omissioni.';

    public function up(): void
    {
        $pagina = Page::query()->where('slug', 'cookie')->first();

        if ($pagina === null) {
            return;
        }

        $corpo = (string) $pagina->body;

        /* Il confronto ignora come sono andate a capo le righe: il testo nasce
           da un heredoc indentato, e una riformattazione innocua renderebbe
           questa migrazione un'operazione che non fa niente in silenzio. */
        $normalizzato = preg_replace('/\s+/u', ' ', $corpo) ?? $corpo;
        $cercato = preg_replace('/\s+/u', ' ', self::VECCHIO) ?? self::VECCHIO;

        if (! str_contains($normalizzato, (string) $cercato)) {
            return;
        }

        $nuovo = <<<'MARKDOWN'
        Questo sito **non condivide nulla con reti pubblicitarie e non ti segue su altri siti.**
        L'elenco completo dei cookie è qui sotto, diviso per finalità, e non è scritto in questa
        pagina: viene dal registro che teniamo aggiornato quando cambia uno strumento. È l'unico
        modo perché non resti indietro.

        ## Sugli annunci, per esteso

        Gli annunci che vedi sono eventi sponsorizzati dai locali della città. Restano qui: nessuna
        rete esterna, nessun pixel, nessun profilo che ti segue altrove.

        Ma **se hai acconsentito agli annunci, guardiamo le categorie degli eventi che hai
        salvato** per scegliere quali mostrarti: se metti in agenda dei concerti, vedrai più
        concerti. È una forma di profilazione — piccola, tutta interna a questo sito, ma è
        corretto chiamarla col suo nome invece di dire che non profiliamo nessuno.

        Senza il tuo consenso gli annunci restano, uguali per tutti: quello che cambia è solo se
        teniamo conto di cosa hai salvato. E i salvataggi restano tuoi in ogni caso — non li
        vendiamo, non li mandiamo a nessuno, non li usiamo per altro.
        MARKDOWN;

        /* Si sostituisce sul testo vero, non su quello normalizzato: la
           normalizzazione serviva solo a riconoscerlo. */
        $posizione = mb_strpos($corpo, 'Questo sito');
        $fine = mb_strpos($corpo, "\n\n", (int) $posizione);

        if ($posizione === false || $fine === false) {
            return;
        }

        $pagina->forceFill([
            'body' => $nuovo.mb_substr($corpo, $fine),
        ])->save();
    }

    public function down(): void
    {
        /* Non si torna indietro: il testo di prima descriveva un sito senza
           annunci, che non esiste più. */
    }
};
