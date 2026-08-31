<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ImportRunStatus;

/**
 * Il resoconto di un'esecuzione (§14.2, «log errori, alert su fallimento»).
 *
 * Le voci sono sei e nessuna è ridondante:
 *
 * - **creati** e **aggiornati** dicono che cosa è cambiato nel catalogo;
 * - **invariati** è la prova dell'idempotenza: alla seconda esecuzione di uno
 *   stesso feed è l'unico contatore che si muove;
 * - **esclusi** conta il filtro di §14.2, ed è ciò che rende visibile quante
 *   voci interne un calendario stava per riversare nel sito;
 * - **annullati** sono le date sparite dal feed, che vengono marcate e mai
 *   cancellate;
 * - **errori** sono le voci che non si sono lasciate interpretare.
 */
final class ImportReport
{
    private int $created = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    private int $excluded = 0;

    private int $cancelled = 0;

    /** @var list<string> */
    private array $errors = [];

    private int $errorCount = 0;

    public function addCreated(): void
    {
        $this->created++;
    }

    public function addUpdated(): void
    {
        $this->updated++;
    }

    public function addUnchanged(): void
    {
        $this->unchanged++;
    }

    public function addExcluded(): void
    {
        $this->excluded++;
    }

    public function addCancelled(int $howMany = 1): void
    {
        $this->cancelled += $howMany;
    }

    /**
     * Gli errori si accumulano tutti nel conteggio ma solo i primi per esteso:
     * `import_sources.last_error` è una colonna che qualcuno deve leggere, e
     * duecento righe identiche non si leggono.
     */
    public function addError(string $message): void
    {
        $this->errorCount++;

        if (count($this->errors) < config()->integer('import.reported_errors')) {
            $this->errors[] = $message;
        }
    }

    public function status(): ImportRunStatus
    {
        return $this->errorCount === 0 ? ImportRunStatus::Success : ImportRunStatus::Partial;
    }

    /**
     * Il testo per `last_error`, o `null` quando non c'è nulla da dire.
     *
     * Non è questo testo a decidere se la sorgente compare fra gli «import
     * falliti» di §14.5: quella domanda la decide `last_status`, perché
     * un'esecuzione riuscita in parte lascia un errore scritto senza essere
     * un guasto.
     */
    public function errorSummary(): ?string
    {
        if ($this->errors === []) {
            return null;
        }

        $summary = implode(' · ', $this->errors);
        $hidden = $this->errorCount - count($this->errors);

        if ($hidden > 0) {
            $summary .= ' · '.__('import.errors.and_more', ['count' => $hidden]);
        }

        return $summary;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, excluded: int, cancelled: int, errors: int, messages: list<string>}
     */
    /**
     * I soli contatori, adatti a riempire i segnaposto di una traduzione.
     *
     * `toArray()` porta con se anche l'elenco dei messaggi di errore, che e
     * una lista: passarlo a `__()` come sostituzione produrrebbe un tipo che
     * il traduttore non sa rendere.
     *
     * @return array<string, int>
     */
    public function counters(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'excluded' => $this->excluded,
            'cancelled' => $this->cancelled,
            'errors' => $this->errorCount,
        ];
    }

    /**
     * Il resoconto per esteso: contatori piu l'elenco dei messaggi di errore.
     *
     * Per riempire i segnaposto di una traduzione servono i soli contatori:
     * vedi `counters()`.
     *
     * @return array{created: int, updated: int, unchanged: int, excluded: int, cancelled: int, errors: int, messages: list<string>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'excluded' => $this->excluded,
            'cancelled' => $this->cancelled,
            'errors' => $this->errorCount,
            'messages' => $this->errors,
        ];
    }
}
