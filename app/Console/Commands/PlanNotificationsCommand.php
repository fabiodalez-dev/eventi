<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Services\Notifications\DigestPlanner;
use Illuminate\Console\Command;

/**
 * Programma i riepiloghi (§15.4) e purga l'archivio degli invii oltre i dodici
 * mesi (§15.9).
 *
 * Sta a parte dal worker perché risponde a un'altra domanda: il worker chiede
 * «cosa devo mandare adesso», questo chiede «cosa sarà da mandare». Le righe
 * nascono in anticipo proprio perché §15.5 pretende che ogni invio previsto
 * sia ispezionabile **prima** di partire — è il motivo per cui esiste una
 * tabella invece di una coda.
 */
final class PlanNotificationsCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'notifications:plan';
        $this->description = __('console.notifications_plan.description');

        parent::__construct();
    }

    public function handle(DigestPlanner $planner): int
    {
        $planned = $planner->plan();
        $purged = $planner->purgeLog();

        $this->info(__('console.notifications_plan.done', [
            'venue_digest' => $planned[NotificationType::VenueDigest->value],
            'daily_digest' => $planned[NotificationType::DailyDigest->value],
            'weekend' => $planned[NotificationType::WeekendNewsletter->value],
            'inactive' => $planned[NotificationType::VenueInactive->value],
            'purged' => $purged,
        ]));

        return self::SUCCESS;
    }
}
