<?php

return [
    'php_binary' => env('SCHEDULER_PHP_BINARY', is_file('/opt/cpanel/ea-php84/root/usr/bin/php') ? '/opt/cpanel/ea-php84/root/usr/bin/php' : PHP_BINDIR.'/php'),
];
