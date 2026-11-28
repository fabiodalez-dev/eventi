<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\City;
use App\Models\SocialConnection;
use App\Services\Operations\SchedulerOverview;

/**
 * «Cron e programmazioni» nell'app della redazione (R12, R-SY-07): la stessa
 * pagina di sola lettura del pannello (`ScheduledOperations` con la vista
 * `filament.scheduled-operations`), come dati.
 *
 * Tutto viene da `SchedulerOverview`, che legge lo scheduler reale e il
 * crontab del server: le due righe di cron da installare, se il crontab le ha
 * già, la coda in uso, i collegamenti social che l'autopost usa, e l'elenco
 * delle attività con espressione, prossimo controllo e storico del monitor
 * (tutto in UTC, come nel pannello). Aprire la pagina non esegue niente.
 */
final class EditorialScheduledOperations
{
    public function __construct(private readonly SchedulerOverview $overview) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $connections = SocialConnection::query()->orderBy('city_id')->get();
        // Il modello non ha la relazione: i nomi delle città in una query sola.
        $cities = City::query()->whereIn('id', $connections->pluck('city_id')->filter()->all())->pluck('name', 'id');

        return [
            'installed' => $this->overview->installed(),
            'cron' => $this->overview->cron(),
            'queue' => config()->string('queue.default'),
            'social' => $connections->map(static fn (SocialConnection $connection): array => [
                'city_id' => $connection->city_id,
                'city' => $cities[$connection->city_id] ?? null,
                'telegram_verified' => $connection->telegram_verified_at !== null,
                'verified_at' => $connection->verified_at?->toIso8601String(),
                'instagram_enabled' => (bool) $connection->instagram_enabled,
                'automatic' => (bool) $connection->automatic,
                'publish_time' => $connection->publish_time,
            ])->values()->all(),
            'tasks' => array_map(static fn (array $task): array => [
                'name' => (string) $task['name'],
                'explanation' => (string) $task['explanation'],
                'command' => (string) $task['command'],
                'expression' => (string) $task['expression'],
                'next_due_date' => isset($task['next_due_date']) ? (string) $task['next_due_date'] : null,
                'last_finished' => $task['last_finished'] ?? null,
                'last_failed' => $task['last_failed'] ?? null,
                'monitored' => (bool) $task['monitored'],
            ], $this->overview->tasks()),
        ];
    }
}
