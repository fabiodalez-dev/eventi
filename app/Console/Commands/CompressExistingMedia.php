<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Imagick;
use Throwable;

/**
 * Ricomprime le locandine gia' sul disco che superano il tetto.
 *
 * **Perche' serve, visto che c'e' gia' il listener.**
 * `CompressOversizedConversion` agisce quando una conversione viene generata:
 * copre tutto cio' che arriva da oggi in poi e niente di cio' che c'era prima.
 * Al primo controllo di salute erano **84 locandine su 495**, la piu' pesante
 * da 383 KB — accumulate nei mesi in cui il tetto non esisteva.
 *
 * Un difetto che si corregge solo in avanti resta a terra per tutto lo storico,
 * e nel frattempo rallenta gli elenchi in cui quelle immagini compaiono.
 *
 * **Non rigenera le conversioni**, le ricomprime: rigenerarle costerebbe
 * quanto rifare tutta la libreria e cambierebbe anche quelle che vanno bene.
 * Qui si tocca solo cio' che sfora, e si tocca il file dove sta.
 */
class CompressExistingMedia extends Command
{
    protected $signature = 'media:compress-oversized
        {--dry-run : Elenca quello che farebbe, senza toccare niente}
        {--limit=0 : Quante al massimo (0 = tutte)}';

    protected $description = 'Ricomprime le locandine da elenco che superano il tetto di peso';

    private const TETTO_BYTE = 120 * 1024;

    private const GRADINI = [70, 60, 50, 40];

    public function handle(): int
    {
        $cartella = storage_path('app/public');

        if (! File::isDirectory($cartella)) {
            $this->info('Nessuna immagine da guardare.');

            return self::SUCCESS;
        }

        $limite = (int) $this->option('limit');
        $prova = (bool) $this->option('dry-run');

        $trovate = 0;
        $ridotte = 0;
        $risparmio = 0;
        $irriducibili = [];

        foreach (File::allFiles($cartella) as $file) {
            /* Solo le varianti da elenco: la `full` la vede chi apre una
               scheda, una alla volta, e puo' pesare di piu' senza danno. */
            if (! str_contains($file->getFilename(), '-card') || $file->getSize() <= self::TETTO_BYTE) {
                continue;
            }

            $trovate++;

            if ($limite > 0 && $ridotte >= $limite) {
                continue;
            }

            $partenza = $file->getSize();

            if ($prova) {
                $this->line(sprintf('  %5d KB  %s', (int) round($partenza / 1024), $file->getFilename()));

                continue;
            }

            try {
                $dopo = $this->ricomprimi($file->getRealPath());
            } catch (Throwable $e) {
                $this->warn('  salto '.$file->getFilename().': '.$e->getMessage());

                continue;
            }

            if ($dopo === null) {
                $irriducibili[] = $file->getFilename();

                continue;
            }

            $ridotte++;
            $risparmio += $partenza - $dopo;

            $this->line(sprintf(
                '  %5d KB → %4d KB   %s',
                (int) round($partenza / 1024),
                (int) round($dopo / 1024),
                $file->getFilename(),
            ));
        }

        if ($prova) {
            $this->info($trovate.' locandine supererebbero il tetto (nessuna toccata: --dry-run).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d su %d ricompresse, %d MB risparmiati.',
            $ridotte,
            $trovate,
            (int) round($risparmio / 1024 / 1024),
        ));

        if ($irriducibili !== []) {
            /*
             * Non rientrano nemmeno alla qualita' minima: il problema e'
             * l'originale — una foto enorme o piena di grana — e va risolto da
             * chi l'ha caricata. Si tiene quella che c'e', perche' brutta e
             * leggera e' peggio di grande e bella.
             */
            $this->warn(count($irriducibili).' restano sopra il tetto anche al minimo: guardare gli originali.');

            foreach (array_slice($irriducibili, 0, 5) as $nome) {
                $this->line('  '.$nome);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Il peso finale, o `null` se non rientra nemmeno al minimo.
     */
    private function ricomprimi(string $percorso): ?int
    {
        foreach (self::GRADINI as $qualita) {
            $immagine = new Imagick($percorso);
            $immagine->setImageCompressionQuality($qualita);
            $immagine->setCompressionQuality($qualita);

            $blob = $immagine->getImageBlob();
            $immagine->clear();

            if (strlen($blob) <= self::TETTO_BYTE) {
                file_put_contents($percorso, $blob);

                return strlen($blob);
            }
        }

        return null;
    }
}
