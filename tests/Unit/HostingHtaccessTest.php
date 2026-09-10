<?php

use App\Support\HostingHtaccess;

it('allows only the exact PHP 8.4 hosting addition and preserves the rest', function () {
    $base = "<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n";
    $handler = <<<'HTACCESS'

# php -- BEGIN cPanel-generated handler, do not edit
# Set the “ea-php84” package as the default “PHP” programming language.
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
HTACCESS;
    expect(HostingHtaccess::isOnlyPhp84Handler($base, $base.$handler."\n"))->toBeTrue()
        ->and(HostingHtaccess::isOnlyPhp84Handler($base, str_replace('RewriteEngine On', 'RewriteEngine Off', $base).$handler))->toBeFalse()
        ->and(HostingHtaccess::isOnlyPhp84Handler($base, $base.$handler."\nDeny from all"))->toBeFalse()
        ->and(HostingHtaccess::isOnlyPhp84Handler($base, $base.str_replace('ea-php84', 'ea-php83', $handler)))->toBeFalse();
});
