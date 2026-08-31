<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Enums\ImportRunStatus;
use App\Models\ImportSource;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Il controllo che §16 chiede ma che nessun pacchetto porta con sé: le sorgenti
 * di import (§14.2) stanno ancora leggendo qualcosa?
 *
 * Un calendario che smette di rispondere non fa cadere il sito e non riempie
 * nessun log di errore: l'esecuzione oraria scrive `failed` in
 * `import_sources.last_status` e prosegue. Il sintomo arriva settimane dopo,
 * come «non ci sono più eventi nuovi», e a quel punto nessuno lo collega a un
 * indirizzo cambiato in silenzio.
 *
 * La soglia è **proporzionale**, non assoluta: con quaranta sorgenti, una che
 * cade è la vita normale di un calendario pubblico e svegliare qualcuno di
 * notte per quella significa insegnargli a ignorare l'avviso. Tutte insieme,
 * invece, non è mai un guasto delle sorgenti — è il nostro.
 */
final class ImportSourcesCheck extends Check
{
    /**
     * Frazione di sorgenti in errore oltre la quale scatta l'avviso.
     */
    private float $warnAboveFailureRatio = 0.0;

    /**
     * Frazione oltre la quale l'avviso diventa allarme. A 1.0 significa
     * «tutte», che è la domanda posta da §16.
     */
    private float $failAboveFailureRatio = 1.0;

    public function warnAboveFailureRatio(float $ratio): self
    {
        $this->warnAboveFailureRatio = $ratio;

        return $this;
    }

    public function failAboveFailureRatio(float $ratio): self
    {
        $this->failAboveFailureRatio = $ratio;

        return $this;
    }

    public function run(): Result
    {
        $active = ImportSource::query()->active()->count();

        /*
         * Nessuna sorgente accesa non è un guasto: è una città che si popola
         * ancora a mano (§14.3). Segnalarlo come rosso renderebbe l'endpoint
         * inutilizzabile proprio all'inizio, che è quando serve di più.
         */
        if ($active === 0) {
            return Result::make()
                ->ok(__('health.import_sources.none'))
                ->shortSummary(__('health.import_sources.summary_none'));
        }

        $failed = ImportSource::query()
            ->active()
            ->where('last_status', ImportRunStatus::Failed->value)
            ->count();

        $ratio = $failed / $active;

        $result = Result::make()
            ->meta([
                'active' => $active,
                'failed' => $failed,
            ])
            ->shortSummary(__('health.import_sources.summary', [
                'failed' => $failed,
                'active' => $active,
            ]));

        if ($ratio >= $this->failAboveFailureRatio) {
            return $result->failed(__('health.import_sources.all_failing', [
                'active' => $active,
            ]));
        }

        if ($ratio > $this->warnAboveFailureRatio) {
            return $result->warning(__('health.import_sources.some_failing', [
                'failed' => $failed,
                'active' => $active,
            ]));
        }

        return $result->ok(__('health.import_sources.ok', ['active' => $active]));
    }
}
