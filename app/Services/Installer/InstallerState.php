<?php

declare(strict_types=1);

namespace App\Services\Installer;

use App\Enums\InstallerStep;
use App\Enums\InstallerTask;
use Illuminate\Contracts\Session\Session;

/**
 * Lo stato del wizard, in sessione (D42, punto 3).
 *
 * Due cose, e solo queste: quali passi sono stati completati e cosa è stato
 * risposto in ciascuno. Da qui nasce l'anti-salto — chi chiede il passo N
 * senza aver completato N-1 non ci arriva — che non è una gentilezza per
 * l'interfaccia ma la condizione per cui il passo di esecuzione può dare per
 * certo di avere tutti i dati che gli servono.
 */
class InstallerState
{
    private const COMPLETED = 'installer.completed_steps';

    private const DATA = 'installer.data';

    private const TASKS = 'installer.completed_tasks';

    private const SUMMARY = 'installer.summary';

    public function __construct(private readonly Session $session) {}

    public function isCompleted(InstallerStep $step): bool
    {
        return in_array($step->value, $this->completedSteps(), true);
    }

    public function complete(InstallerStep $step): void
    {
        $completed = $this->completedSteps();

        if (! in_array($step->value, $completed, true)) {
            $completed[] = $step->value;
            $this->session->put(self::COMPLETED, $completed);
        }
    }

    /**
     * Il passo è raggiungibile solo se tutti quelli che lo precedono sono
     * stati completati.
     */
    public function canOpen(InstallerStep $step): bool
    {
        foreach ($step->previous() as $earlier) {
            if (! $this->isCompleted($earlier)) {
                return false;
            }
        }

        return true;
    }

    /** Il primo passo non ancora completato: è dove torna chi ha saltato. */
    public function firstIncomplete(): InstallerStep
    {
        foreach (InstallerStep::cases() as $step) {
            if (! $this->isCompleted($step)) {
                return $step;
            }
        }

        return InstallerStep::Done;
    }

    /**
     * L'installazione è cominciata? Serve al `InstallerGate`: durante il passo
     * di esecuzione il database *è già* popolato, e senza questa domanda il
     * controllo «database vivo e marcatore assente» scambierebbe l'installazione
     * in corso per una preesistente e caccerebbe fuori chi la sta facendo.
     */
    public function hasStarted(): bool
    {
        return $this->completedSteps() !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(InstallerStep $step, array $data): void
    {
        $this->session->put(self::DATA.'.'.$step->value, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(InstallerStep $step): array
    {
        $data = $this->session->get(self::DATA.'.'.$step->value, []);

        return is_array($data) ? $data : [];
    }

    public function value(InstallerStep $step, string $key, string $default = ''): string
    {
        $value = $this->get($step)[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function isDone(InstallerTask $task): bool
    {
        return in_array($task->value, $this->completedTasks(), true);
    }

    public function markDone(InstallerTask $task): void
    {
        $tasks = $this->completedTasks();

        if (! in_array($task->value, $tasks, true)) {
            $tasks[] = $task->value;
            $this->session->put(self::TASKS, $tasks);
        }
    }

    /** La prossima operazione della checklist, o `null` se non ne restano. */
    public function nextTask(): ?InstallerTask
    {
        foreach (InstallerTask::ordered() as $task) {
            if (! $this->isDone($task)) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Il riepilogo mostrato dalla schermata finale: cosa è stato configurato e
     * cosa resta da fare a mano. È l'unica cosa che sopravvive alla pulizia,
     * ed è fatto apposta di soli dati che si possono mostrare.
     *
     * @param  array<string, mixed>  $summary
     */
    public function putSummary(array $summary): void
    {
        $this->session->put(self::SUMMARY, $summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $summary = $this->session->get(self::SUMMARY, []);

        return is_array($summary) ? $summary : [];
    }

    /**
     * A installazione finita le risposte si buttano: contengono la password
     * del database e quella dell'amministratore, e non hanno alcuna ragione di
     * sopravvivere alla schermata che le ha usate.
     *
     * I passi completati restano: sono ciò che tiene raggiungibile la
     * schermata finale per il resto della sessione, dopo che il marcatore ha
     * già chiuso l'installer a chiunque altro.
     */
    public function forgetSecrets(): void
    {
        $this->session->forget([self::DATA, self::TASKS]);
    }

    /**
     * @return array<int, string>
     */
    private function completedSteps(): array
    {
        $completed = $this->session->get(self::COMPLETED, []);

        return is_array($completed) ? array_values(array_filter($completed, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    private function completedTasks(): array
    {
        $tasks = $this->session->get(self::TASKS, []);

        return is_array($tasks) ? array_values(array_filter($tasks, 'is_string')) : [];
    }
}
