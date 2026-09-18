<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Carpool\CarpoolRetention;
use Illuminate\Console\Command;

final class CarpoolPurge extends Command
{
    protected $signature = 'carpool:purge';

    protected $description = 'Erase expired carpool data, respecting documented case holds';

    public function handle(CarpoolRetention $retention): int
    {
        $this->line(json_encode($retention->purge(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
