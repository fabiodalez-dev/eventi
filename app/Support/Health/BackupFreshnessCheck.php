<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

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
     * Sotto questa soglia un archivio non e' un backup: e' cio' che resta di
     * un backup interrotto. Il valore e' volutamente basso — serve a
     * distinguere un troncamento da un archivio piccolo, non a giudicare
     * quanto debbano pesare i dati.
     */
    private int $byteMinimi = 1024 * 1024;

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

        if ($peso < $this->byteMinimi) {
            return $risultato->failed(sprintf(
                'L ultimo backup pesa %d KB: e troppo poco per essere un archivio intero, probabilmente si e interrotto mentre scriveva.',
                (int) round($peso / 1024),
            ));
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
