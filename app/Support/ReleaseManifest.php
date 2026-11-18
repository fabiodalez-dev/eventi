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
     * (120000), sottomoduli (160000) e ogni nome che non sia un file statico
     * (vedi `isStaticName`). Un elenco illeggibile non passa.
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
            if ($path === '' || $mode === '120000' || $mode === '160000' || ! self::isStaticName($name)) {
                return $path === '' ? $line : $path;
            }
        }

        return null;
    }

    /**
     * Un nome di asset accettabile: estensione finale fra quelle statiche, e
     * nessuna estensione eseguibile in mezzo. Apache (`AddType` in
     * `public/.htaccess`) guarda tutte le estensioni del nome, non solo
     * l'ultima: `x.php.abc` o `x.phtml.txt` possono finire all'interprete.
     */
    private static function isStaticName(string $name): bool
    {
        if (preg_match('/^[a-z0-9_@~+-][a-z0-9_@~+.-]*\.(css|js|mjs|json|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|txt|webmanifest)$/D', $name) !== 1) {
            return false;
        }

        return preg_match('/\.(php\d*|phtml|phar|phps|pht|cgi|pl|py|sh|shtml|htaccess|user\.ini)(\.|$)/', $name) !== 1;
    }
}
