<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Il worker di §15.5, invocato dallo scheduler ogni cinque minuti.
 *
 * Non è un demone e non deve diventarlo: sullo spazio condiviso non esistono
 * processi permanenti (D5), e questo comando è pensato per essere lanciato,
 * fare il proprio giro e uscire. `--limit` serve a chi lo esegue a mano per
 * guardare cosa succede su poche righe prima di lasciarlo andare su tutte.
 */
final class SendScheduledNotificationsCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'notifications:send'
            .' {--limit= : '.__('console.notifications_send.option_limit').'}';

        $this->description = __('console.notifications_send.description');

        parent::__construct();
    }

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limit = $this->option('limit');

        $summary = $dispatcher->run(is_numeric($limit) ? max((int) $limit, 1) : null);

        if ($summary['claimed'] === 0) {
            $this->info(__('console.notifications_send.empty'));

            return self::SUCCESS;
        }

        $this->info(__('console.notifications_send.done', $summary));

        return self::SUCCESS;
    }
}
