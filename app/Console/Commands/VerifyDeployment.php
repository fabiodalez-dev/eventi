<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class VerifyDeployment extends Command
{
    protected $signature = 'deploy:verify';

    protected $description = 'Verifica database, migrazioni, rotte e tutti gli asset prima di riaprire il sito.';

    public function handle(): int
    {
        try {
            DB::select('SELECT 1');
            $migrator = app('migrator');
            $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
            if ($pending !== []) {
                throw new RuntimeException('Migrazioni ancora da applicare: '.implode(', ', $pending));
            }
            foreach (['home', 'events.show', 'events.occurrence', 'social.preview', 'filament.admin.pages.seo-overview', 'ops.release'] as $route) {
                if (! Route::has($route)) {
                    throw new RuntimeException('Rotta mancante: '.$route);
                }
            }
            $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || $manifest === []) {
                throw new RuntimeException('Manifest Vite vuoto.');
            }
            foreach ($manifest as $entry) {
                foreach ([$entry['file'], ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
                    if (! is_file(public_path('build/'.$file))) {
                        throw new RuntimeException('Asset mancante: '.$file);
                    }
                }
            }
            $this->info('Database, migrazioni, rotte e asset verificati.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
