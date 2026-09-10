<?php

declare(strict_types=1);

namespace App\Support;

final class HostingHtaccess
{
    public static function isOnlyPhp84Handler(string $original, string $current): bool
    {
        $handler = <<<'HTACCESS'

# php -- BEGIN cPanel-generated handler, do not edit
# Set the “ea-php84” package as the default “PHP” programming language.
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
HTACCESS;

        return rtrim($current) === rtrim($original)."\n".$handler;
    }
}
