<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Toglie dalla cache su file le voci scadute che nessuno rilegge.
 *
 * Il driver `file` cancella una voce scaduta solo quando qualcuno la chiede
 * di nuovo: le chiavi che cambiano nel tempo — il ticker di ogni città, le
 * finestre «in corso adesso», i contatori dei pannelli — restano sul disco per
 * sempre. Su hosting condiviso il limite che si tocca per primo non è lo
 * spazio ma il numero di file, e questa cartella li accumulava a migliaia.
 *
 * I primi dieci byte di ogni file sono il momento di scadenza in secondi
 * Unix: è il formato del driver, e basta confrontarlo con l'ora corrente.
 */
final class PruneFileCacheCommand extends Command
{
    protected $signature = 'cache:prune-file-store {--dry-run : Conta soltanto, senza cancellare}';

    protected $description = 'Cancella le voci scadute della cache su file';

    public function handle(): int
    {
        $path = (string) config('cache.stores.file.path');

        if ($path === '' || ! File::isDirectory($path)) {
            $this->info('Nessuna cache su file da pulire.');

            return self::SUCCESS;
        }

        $now = time();
        $expired = 0;
        $kept = 0;

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== '') {
                continue;
            }

            $handle = @fopen($file->getPathname(), 'rb');
            if ($handle === false) {
                continue;
            }
            $stamp = fread($handle, 10);
            fclose($handle);

            if (! is_string($stamp) || ! ctype_digit($stamp) || (int) $stamp >= $now) {
                $kept++;

                continue;
            }

            $expired++;
            if (! $this->option('dry-run')) {
                @unlink($file->getPathname());
            }
        }

        $this->info(sprintf('%d voci scadute%s, %d ancora valide.', $expired, $this->option('dry-run') ? ' (non cancellate)' : ' cancellate', $kept));

        return self::SUCCESS;
    }
}
