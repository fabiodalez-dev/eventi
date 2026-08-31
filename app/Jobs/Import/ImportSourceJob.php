<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Models\ImportSource;
use App\Services\Import\ImportRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Una sorgente, un lavoro.
 *
 * È il perno di §14.2 sull'esecuzione oraria: **una sorgente irraggiungibile
 * non deve bloccare le altre**. Se l'import fosse un ciclo dentro un comando,
 * un calendario che impiega trenta secondi a non rispondere ritarderebbe tutti
 * quelli in coda dietro di lui, e tre di questi manderebbero l'esecuzione oltre
 * l'ora successiva. Un lavoro per sorgente li rende indipendenti: ognuno ha il
 * proprio tempo massimo, i propri tentativi e il proprio errore.
 *
 * `ShouldBeUnique` sull'identificativo della sorgente evita la sovrapposizione
 * più insidiosa — l'esecuzione delle 15:00 ancora in corso quando parte quella
 * delle 16:00 — che senza produrrebbe due scritture concorrenti sugli stessi
 * eventi. Il blocco scade da solo dopo il tempo massimo del lavoro, così un
 * processo ucciso non lascia una sorgente ferma per sempre.
 */
final class ImportSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly int $sourceId)
    {
        $this->tries = config()->integer('import.job.tries');
        $this->timeout = config()->integer('import.job.timeout');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function uniqueFor(): int
    {
        return config()->integer('import.job.timeout');
    }

    /**
     * Attesa crescente fra un tentativo e l'altro: un guasto di rete dura
     * secondi, una manutenzione dura minuti.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config()->array('import.job.backoff');

        return $backoff;
    }

    public function handle(ImportRunner $runner): void
    {
        $source = ImportSource::query()->find($this->sourceId);

        if ($source === null || ! $source->is_active) {
            return;
        }

        $report = $runner->run($source);

        Log::info('import.run', ['source' => $this->sourceId, ...$report->toArray()]);
    }

    /**
     * L'esito è già scritto sulla sorgente da `ImportRunner`, che registra
     * `last_run_at`, `last_status` e `last_error` **prima** di rilanciare: la
     * dashboard di §14.5 vede il guasto anche quando il lavoro finisce nella
     * tabella dei falliti.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('import.failed', [
            'source' => $this->sourceId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
