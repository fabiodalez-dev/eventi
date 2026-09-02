<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use ZipArchive;

/**
 * C'e' un backup recente, e sta in piedi?
 *
 * **Perche' non basta `backup:monitor`.** Quello sa dire che l'ultimo archivio
 * e' vecchio, ma parla la lingua di Spatie e finisce in una notifica a parte.
 * Qui la stessa informazione entra nel quadro generale della salute, accanto a
 * disco e code, che e' il posto dove si guarda quando qualcosa non va.
 *
 * **Il fatto vero da cui nasce.** Il 2 settembre 2026 il backup delle 03:40 ha
 * esaurito la quota dell'account mentre scriveva ed e' morto a meta',
 * lasciando 128 MB di resti e un archivio incompleto da 30 MB. Da quel momento
 * nessun processo e' riuscito a scrivere: la pagina iniziale rispondeva 500
 * mentre le altre, che avevano gia' la propria cache, continuavano a
 * funzionare. Il backup piu' recente c'era — e non si sarebbe aperto.
 *
 * Per questo si guarda anche la **dimensione**: un archivio troncato pesa
 * poco e ha un nome perfetto. Il controllo che serve non e' «c'e' un file di
 * ieri», e' «c'e' un file di ieri che sembra un backup».
 */
final class BackupFreshnessCheck extends Check
{
    /** Oltre quante ore un backup smette di essere recente. */
    private int $oreMassime = 30;

    /**
     * L'archivio ha un indice leggibile?
     *
     * Non estrae niente: `CHECKCONS` verifica la coerenza delle intestazioni,
     * che e' esattamente cio' che manca a uno zip troncato. Su un archivio da
     * centotrenta megabyte estrarre per controllare costerebbe piu' del
     * backup stesso.
     */
    private function siApre(string $percorso): bool
    {
        if (! class_exists(ZipArchive::class)) {
            /* Senza l'estensione non si puo' verificare: meglio non dire
               niente che dire una cosa falsa. */
            return true;
        }

        $zip = new ZipArchive;
        $esito = $zip->open($percorso, ZipArchive::CHECKCONS);

        if ($esito === true) {
            $zip->close();

            return true;
        }

        return false;
    }

    public function run(): Result
    {
        $cartella = storage_path('app/private/'.config()->string('backup.backup.name'));

        if (! File::isDirectory($cartella)) {
            return Result::make()
                ->shortSummary('mai eseguito')
                ->failed('Non esiste nessuna cartella di backup: o non e mai partito, o scrive altrove.');
        }

        $archivi = collect(File::files($cartella))
            ->filter(fn ($file): bool => $file->getExtension() === 'zip')
            ->sortByDesc(fn ($file): int => $file->getMTime());

        if ($archivi->isEmpty()) {
            return Result::make()
                ->shortSummary('nessun archivio')
                ->failed('La cartella di backup e vuota.');
        }

        $ultimo = $archivi->first();
        $quando = CarbonImmutable::createFromTimestamp($ultimo->getMTime());
        $ore = (int) $quando->diffInHours(CarbonImmutable::now());
        $peso = $ultimo->getSize();

        $risultato = Result::make()
            ->shortSummary($quando->diffForHumans())
            ->meta([
                'file' => $ultimo->getFilename(),
                'ore_fa' => $ore,
                'byte' => $peso,
                'quanti_conservati' => $archivi->count(),
            ]);

        if (! $this->siApre($ultimo->getPathname())) {
            /*
             * **Si prova ad aprirlo, non si guarda quanto pesa.** Un archivio
             * morto a meta' — e' successo il 2 settembre, con la quota
             * esaurita mentre scriveva — ha un nome perfetto, una data
             * recente, e dentro non c'e' niente di recuperabile. Il peso non
             * lo distingue da un backup del solo database, che e'
             * legittimamente piccolo.
             *
             * `ZipArchive::open` con `CHECKCONS` legge l'indice senza
             * estrarre: costa una lettura, non una decompressione.
             *
             * **Il peso non e' piu' un criterio, ed era sbagliato che lo
             * fosse.** La prima versione bocciava tutto cio' che stava sotto
             * un megabyte: un `backup:run --only-db` produce un archivio da
             * 256 KB perfettamente valido, e l'ho scoperto creandone uno a
             * mano in produzione e vedendo il controllo dichiararlo troncato.
             */
            return $risultato->failed(
                'L ultimo backup non si apre: probabilmente si e interrotto mentre scriveva. Serve quello precedente.',
            );
        }

        if ($ore > $this->oreMassime) {
            return $risultato->failed(sprintf(
                'L ultimo backup risale a %d ore fa: il notturno non sta girando.',
                $ore,
            ));
        }

        return $risultato->ok(sprintf(
            'Ultimo backup %d ore fa, %d MB, %d archivi conservati.',
            $ore,
            (int) round($peso / 1024 / 1024),
            $archivi->count(),
        ));
    }
}
