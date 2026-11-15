<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Il `.htaccess` che il deploy scrive in `public/build` dopo aver estratto
 * gli asset: la seconda difesa dopo `ReleaseManifest::firstNonStaticEntry()`.
 *
 * Non sta nel ramo degli asset, che deve contenere solo file statici, né in
 * `public/.htaccess`, che sul server cPanel ha una riga aggiunta a mano e che,
 * cambiato nel repository, fermerebbe il `merge --ff-only` del deploy. Provato
 * sul server il 15/11/2026: CSS 200, `.php` e `.php.abc` 403; senza questa
 * regola un `.php` in una sottocartella di `public` veniva eseguito.
 */
final class BuildHtaccess
{
    public const CONTENT = <<<'HTACCESS'
# Scritto dal deploy (App\Support\BuildHtaccess): qui si servono solo file statici.
<IfModule mime_module>
    RemoveHandler .php .php8 .phtml .phar .pht .phps .cgi .pl .py .sh .shtml
    RemoveType .php .php8 .phtml .phar .pht .phps
</IfModule>
<FilesMatch "\.(?i:php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|shtml)(\.|$)">
    Require all denied
</FilesMatch>

HTACCESS;

    public static function write(string $buildDirectory): bool
    {
        return file_put_contents(rtrim($buildDirectory, '/').'/.htaccess', self::CONTENT) !== false;
    }
}
