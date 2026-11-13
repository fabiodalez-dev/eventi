<?php

declare(strict_types=1);

namespace App\Support;

final class ReleaseManifest
{
    public static function matches(mixed $manifest, mixed $sha): bool
    {
        return is_string($sha)
            && preg_match('/^[a-f0-9]{40}$/D', $sha) === 1
            && is_array($manifest)
            && ($manifest['sha'] ?? null) === $sha
            && ($manifest['checks'] ?? null) === 'passed'
            && isset($manifest['run_id'])
            && ctype_digit((string) $manifest['run_id']);
    }

    /**
     * Il primo elemento dell'albero degli asset che non è un file statico, o
     * `null` se sono tutti statici. Legge l'output di `git ls-tree -r`
     * («modo tipo sha<TAB>percorso»): si rifiutano collegamenti simbolici
     * (120000), sottomoduli (160000), `.htaccess` e qualunque estensione che
     * il server potrebbe eseguire. Un elenco illeggibile non passa.
     */
    public static function firstNonStaticEntry(?string $tree): ?string
    {
        if ($tree === null || trim($tree) === '') {
            return '(elenco degli asset non leggibile)';
        }
        foreach (preg_split('/\R/', trim($tree)) ?: [] as $line) {
            [$meta, $path] = array_pad(explode("\t", $line, 2), 2, '');
            $mode = strtok($meta, ' ');
            $name = strtolower(basename($path));
            if ($path === '' || $mode === '120000' || $mode === '160000' || $name === '.htaccess' || $name === '.user.ini'
                || preg_match('/\.(php\d*|phtml|phar|phps|pht|cgi|pl|py|sh|shtml)$/D', $name) === 1) {
                return $path === '' ? $line : $path;
            }
        }

        return null;
    }
}
