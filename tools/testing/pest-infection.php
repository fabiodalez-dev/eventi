#!/usr/bin/env php
<?php

declare(strict_types=1);

use PHPUnit\Runner\Version;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Infection usa la versione per scegliere schema XML e opzioni PHPUnit.
// Pest --version espone invece la propria major, diversa da quella del motore.
if (in_array('--version', $_SERVER['argv'], true)) {
    echo 'PHPUnit '.Version::id().PHP_EOL;
    exit(0);
}

require dirname(__DIR__, 2).'/vendor/pestphp/pest/bin/pest';
