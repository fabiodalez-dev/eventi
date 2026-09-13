<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\FacebookEventImport;
use Illuminate\Console\Command;

final class CheckFacebookRuntime extends Command
{
    protected $signature = 'facebook:check {--browser : Verifica esplicitamente Chromium, non richiesto dal percorso HTTP}';

    protected $description = 'Verifica il runtime HTTP dell’importazione o, su richiesta, Chromium.';

    public function handle(FacebookEventImport $import): int
    {
        try {
            $browser = (bool) $this->option('browser');
            $import->checkRuntime($browser);
            $this->info($browser ? 'Chromium: avvio, rendering e JavaScript verificati.' : 'Import HTTP: cURL, DOM e parser Node verificati; Chromium non richiesto.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
