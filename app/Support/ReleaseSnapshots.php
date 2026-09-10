<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ReleaseSnapshots
{
    /** Remove only recognized code/build archives; never traverse links or other data. */
    public function prune(string $root, int $keep = 10): int
    {
        if (! is_dir($root) || is_link($root)) {
            return 0;
        }

        $snapshots = [];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || ! preg_match('/^\d{8}-\d{6}-[a-f0-9]{12}$/D', basename($directory))) {
                continue;
            }
            $files = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
            sort($files);
            if ($files !== ['build.tar.gz', 'code.tar']) {
                continue;
            }
            foreach ($files as $file) {
                if (is_link($directory.'/'.$file) || ! is_file($directory.'/'.$file)) {
                    continue 2;
                }
            }
            $snapshots[] = $directory;
        }
        rsort($snapshots, SORT_STRING);

        $removed = 0;
        foreach (array_slice($snapshots, max(2, $keep)) as $directory) {
            foreach (['build.tar.gz', 'code.tar'] as $file) {
                if (! unlink($directory.'/'.$file)) {
                    throw new RuntimeException('Impossibile eliminare un vecchio archivio di rilascio: '.$directory);
                }
            }
            if (! rmdir($directory)) {
                throw new RuntimeException('Impossibile rimuovere la cartella del vecchio rilascio: '.$directory);
            }
            $removed++;
        }

        return $removed;
    }
}
