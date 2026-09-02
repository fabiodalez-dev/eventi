<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\File;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Le locandine pesano quanto devono?
 *
 * **Il fatto da cui nasce.** Una locandina servita in apertura pesava 231 KB —
 * anche convertita in AVIF, che e' il formato buono. Su banda lenta sono piu'
 * di un secondo di rete occupata, e il referto delle prestazioni non lo
 * riportava come «immagine pesante»: lo riportava come «tempo di disegno
 * alto», che manda a cercare nel codice un difetto che sta in un file caricato
 * da qualcuno. E' costata un'indagine.
 *
 * `CompressOversizedConversion` ricomprime le varianti sopra il tetto, ma non
 * puo' fare miracoli: se nemmeno alla qualita' minima l'immagine rientra, la
 * tiene com'e' — perche' brutta e leggera e' peggio di grande e bella — e
 * scrive nel registro. Questo controllo e' cio' che rende visibile quel
 * registro senza doverlo leggere: quante ce ne sono, e quanto pesa la peggiore.
 *
 * **Guarda solo le varianti `card`**, che sono quelle che finiscono negli
 * elenchi e in apertura. La `full` puo' pesare di piu' senza danno: la vede
 * chi apre una scheda, uno alla volta, non chi scorre una pagina con
 * ventiquattro locandine.
 */
final class MediaWeightCheck extends Check
{
    /** Il tetto oltre cui una variante da elenco pesa troppo. */
    private int $tettoByte = 120 * 1024;

    /**
     * Quale frazione puo' sforare prima che sia un problema e non un caso.
     *
     * **Proporzionale e non assoluta**, per la stessa ragione di
     * `ImportSourcesCheck`: alcune immagini non rientrano nemmeno alla
     * qualita' minima — una foto notturna piena di grana e' irriducibile — e
     * con una soglia fissa il controllo resterebbe rosso per sempre. Un
     * controllo sempre rosso viene spento dopo la seconda volta, e allora non
     * segnala piu' nemmeno il caso in cui ne arrivano cento.
     */
    /*
     * **Il 10% e' tarato sui numeri veri, non scelto per far passare il
     * controllo.** In produzione, dopo aver ricompresso tutto il
     * ricomprimibile — da 79 a 27 su 436 — quel che resta e' irriducibile per
     * natura: fotografie notturne e grafiche piene di grana che nessuna
     * qualita' perdona. Sopra il 10% non e' piu' la coda naturale: o il
     * listener ha smesso di funzionare, o e' arrivato un import di immagini
     * enormi. Sotto, sono casi singoli da sistemare con chi li ha caricati.
     */
    private float $frazioneTollerata = 0.10;

    public function run(): Result
    {
        $cartella = storage_path('app/public');

        if (! File::isDirectory($cartella)) {
            return Result::make()->shortSummary('niente da guardare')->ok('Nessuna immagine caricata.');
        }

        $pesanti = [];
        $guardate = 0;

        /*
         * `allFiles` legge tutto l'albero: qui dentro ci sono le conversioni di
         * ogni locandina, e non c'e' un indice che dica quanto pesano. E' il
         * motivo per cui questo controllo gira in coda con gli altri e non a
         * ogni richiesta.
         */
        foreach (File::allFiles($cartella) as $file) {
            $nome = $file->getFilename();

            if (! str_contains($nome, '-card')) {
                continue;
            }

            $guardate++;

            if ($file->getSize() > $this->tettoByte) {
                $pesanti[$nome] = (int) round($file->getSize() / 1024);
            }
        }

        arsort($pesanti);

        $risultato = Result::make()
            ->shortSummary(count($pesanti).' sopra il tetto')
            ->meta([
                'sopra_il_tetto' => count($pesanti),
                'guardate' => $guardate,
                'tetto_kb' => (int) round($this->tettoByte / 1024),
                'le_peggiori' => array_slice($pesanti, 0, 5, preserve_keys: true),
            ]);

        if ($pesanti === []) {
            return $risultato->ok(sprintf('Tutte le %d locandine da elenco stanno sotto i %d KB.', $guardate, (int) round($this->tettoByte / 1024)));
        }

        $messaggio = sprintf(
            '%d locandine su %d superano i %d KB (la piu pesante %d KB): rallentano gli elenchi in cui compaiono.',
            count($pesanti),
            $guardate,
            (int) round($this->tettoByte / 1024),
            (int) reset($pesanti),
        );

        $frazione = $guardate > 0 ? count($pesanti) / $guardate : 0.0;

        return $frazione > $this->frazioneTollerata
            ? $risultato->failed($messaggio)
            : $risultato->warning($messaggio.' Sono poche: probabilmente originali irriducibili.');
    }
}
