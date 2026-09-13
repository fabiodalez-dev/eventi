<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\FacebookEventImport;
use Illuminate\Console\Command;

final class CheckFacebookRuntime extends Command
{
    protected $signature = 'facebook:check';

    protected $description = 'Avvia Chromium e verifica rendering e JavaScript con lo stesso runtime dell’importazione.';

    public function handle(FacebookEventImport $import): int
    {
        try {
            $import->checkRuntime();
            $this->info('Chromium: avvio, rendering e JavaScript verificati.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
