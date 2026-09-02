<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Il guardiano del backup notturno.
 *
 * **Perche' esiste.** Il 2 settembre 2026 il backup delle 03:40 ha riempito
 * la quota dell'account mentre scriveva, ed e' morto a meta'. Da quel momento
 * nessun processo e' piu' riuscito a scrivere un byte: niente cache, niente
 * sessioni, niente log — e la home ha cominciato a rispondere 500 mentre le
 * altre pagine, che avevano gia' la propria cache, continuavano a funzionare.
 * A terra non ci ha messo il sito un guasto del sito: ce l'ha messo il suo
 * backup.
 *
 * Il fallimento in se' e' accettabile e §16 lo prevede: `backup:monitor` si
 * accorge del silenzio e avvisa. Quello che non e' accettabile e' che un
 * lavoro di manutenzione porti con se' l'applicazione.
 *
 * **Perche' non `disk_free_space()`.** Sarebbe la risposta ovvia e qui e' la
 * risposta sbagliata: su un hosting condiviso riporta il volume di tutti —
 * cinquantadue gigabyte liberi, mentre l'account aveva finito i suoi. La
 * quota non si vede da li'.
 *
 * Quindi non si stima: si prova. Un file della dimensione voluta, scritto
 * dove finirebbe il backup e cancellato subito. E' l'unica verifica che
 * risponde alla domanda vera — «riesco a scrivere?» — invece che a una che le
 * somiglia.
 */
final class SpazioSufficiente
{
    /**
     * Quanto margine pretendere prima di lasciar partire il backup.
     *
     * L'archivio di questo progetto pesa circa 130 MB e ne serve il doppio,
     * perche' viene composto in una cartella temporanea e poi copiato. Non si
     * prova a scrivere tutto quel peso — sarebbe lento e occuperebbe davvero
     * lo spazio che stiamo misurando: 64 MB bastano a distinguere «pieno» da
     * «c'e' posto», che e' la sola domanda a cui questo controllo risponde.
     */
    private const MEGABYTE_DI_PROVA = 64;

    /**
     * La condizione da passare a `->when()` nello scheduler.
     */
    public static function perIlBackup(): callable
    {
        return static fn (): bool => self::verifica();
    }

    public static function verifica(): bool
    {
        self::rimuoviResiduiDiUnGiroFallito();

        $cartella = storage_path('app/backup-temp');
        $prova = $cartella.'/.prova-spazio';

        try {
            File::ensureDirectoryExists($cartella);

            $file = fopen($prova, 'wb');

            if ($file === false) {
                return self::rifiuta('non riesco ad aprire un file nella cartella di lavoro');
            }

            /*
             * A blocchi e non in un colpo solo: un'unica stringa da 64 MB
             * starebbe tutta in memoria, e su un piano condiviso il limite di
             * memoria arriva prima di quello del disco — fallirebbe il
             * controllo invece della cosa controllata.
             */
            $blocco = str_repeat('0', 1024 * 1024);

            for ($i = 0; $i < self::MEGABYTE_DI_PROVA; $i++) {
                if (fwrite($file, $blocco) === false) {
                    fclose($file);

                    return self::rifiuta('lo spazio e finito dopo '.$i.' MB');
                }
            }

            fclose($file);

            return true;
        } catch (Throwable $e) {
            return self::rifiuta($e->getMessage());
        } finally {
            File::delete($prova);
        }
    }

    /**
     * Da quanto una cartella temporanea dev'essere ferma per considerarla
     * abbandonata. Il backup di questo progetto ci mette minuti, non ore: se
     * dopo due non si e' mosso nulla, quel processo non c'e' piu'.
     */
    private const ORE_PRIMA_DI_CONSIDERARLI_ABBANDONATI = 2;

    /**
     * I resti di un backup morto a meta'.
     *
     * Spatie ripulisce la propria cartella temporanea quando finisce, in un
     * modo o nell'altro — ma non quando muore proprio mentre scrive. Il giro
     * fallito ne aveva lasciati 128 MB, che sono rimasti a occupare la quota
     * gia' esaurita e a tenere il sito a terra fino all'intervento a mano.
     *
     * **Ma solo se sono davvero resti.** La prima versione cancellava quella
     * cartella senza guardare, e in prova ha distrutto un backup che stava
     * lavorando in quel momento: `withoutOverlapping` protegge dal secondo
     * giro dello scheduler, non da un `backup:run` lanciato a mano mentre
     * questo controllo passa di li'. Cancellare il lavoro di qualcun altro per
     * fare spazio e' esattamente il genere di danno che questa classe esiste
     * per evitare.
     */
    private static function rimuoviResiduiDiUnGiroFallito(): void
    {
        $temp = storage_path('app/backup-temp/temp');

        if (! File::isDirectory($temp)) {
            return;
        }

        $ultimoTocco = File::lastModified($temp);
        $limite = now()->subHours(self::ORE_PRIMA_DI_CONSIDERARLI_ABBANDONATI)->getTimestamp();

        if ($ultimoTocco > $limite) {
            Log::info('[backup] C\'e una cartella di lavoro ancora fresca: la lascio stare, potrebbe esserci un backup in corso.');

            return;
        }

        Log::warning('[backup] Trovati i resti di un backup interrotto, fermi da piu di '
            .self::ORE_PRIMA_DI_CONSIDERARLI_ABBANDONATI.' ore: li rimuovo prima di ricominciare.');

        File::deleteDirectory($temp);
    }

    private static function rifiuta(string $motivo): bool
    {
        Log::error('[backup] Salto il backup di stanotte: '.$motivo.'. '
            .'Meglio nessun backup che un sito a terra — `backup:monitor` avvisera del buco.');

        return false;
    }
}
