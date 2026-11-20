#!/usr/bin/env php
<?php

declare(strict_types=1);

use PHPUnit\Runner\Version;

/*
 * Pest per Infection.
 *
 * Infection parla con PHPUnit, non con Pest, e ne legge tre cose che Pest
 * non dà nella forma attesa. Senza questi tre aggiustamenti la misura non
 * misura niente: ogni mutante risultava «ucciso» anche con `--noop`.
 *
 * 1. La versione: Infection la usa per scegliere schema XML e opzioni, e
 *    `pest --version` dà la propria major, diversa da quella del motore.
 * 2. L'esito: Infection cerca nell'uscita «OK (» di PHPUnit, che Pest non
 *    stampa mai. Senza, ogni esecuzione sembra fallita e ogni mutante ucciso.
 *    Per questo Pest gira in un processo figlio e alla fine si stampa
 *    «OK (pest)» o «FAILURES!» secondo il suo codice d'uscita.
 * 3. I nomi nel JUnit: Pest nomina le classi dei test a funzioni `Tests\…`
 *    con percorsi relativi, mentre la copertura le chiama `P\Tests\…` con
 *    percorsi assoluti. Senza allinearli Infection si ferma con
 *    `TestNotFound`.
 *
 * Prova: `vendor/bin/infection app/Support/SafeUrl.php --noop` deve dare 0
 * mutanti uccisi.
 */

$root = dirname(__DIR__, 2);

/*
 * Se il file di un test dichiara davvero la sua classe. Si legge il file
 * invece di caricarlo: un file di Pest a funzioni, incluso fuori da Pest,
 * chiamerebbe `it()` e si fermerebbe.
 */
function testPath(string $root, string $class): string
{
    return $root.'/tests/'.str_replace('\\', '/', substr($class, strlen('Tests\\'))).'.php';
}

function declaresClass(string $root, string $class): bool
{
    $path = testPath($root, $class);
    $short = substr($class, (int) strrpos($class, '\\') + 1);

    return is_file($path) && preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+'.preg_quote($short, '/').'\b/m', (string) file_get_contents($path)) === 1;
}

require $root.'/vendor/autoload.php';

$args = array_slice($_SERVER['argv'], 1);

if (in_array('--version', $args, true)) {
    echo 'PHPUnit '.Version::id().PHP_EOL;
    exit(0);
}

$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/pestphp/pest/bin/pest');

foreach ($args as $arg) {
    $command .= ' '.escapeshellarg($arg);
}

passthru($command, $code);

$junit = null;

foreach ($args as $index => $arg) {
    if ($arg === '--log-junit') {
        $junit = $args[$index + 1] ?? null;
    } elseif (str_starts_with($arg, '--log-junit=')) {
        $junit = substr($arg, strlen('--log-junit='));
    }
}

if (is_string($junit) && is_file($junit)) {
    $xml = (string) file_get_contents($junit);

    /* Ogni gruppo di test prende il nome che ha nella copertura e il
       percorso assoluto del suo file. Una classe di PHPUnit vera
       (`final class SafeUrlTest extends TestCase`) tiene il nome, ma Pest le
       scrive un file inventato («Safe Url (Tests\…)») che va corretto. */
    $xml = preg_replace_callback(
        '/<testsuite name="(Tests\\\\[A-Za-z0-9_\\\\]+)" file="[^"]*"/',
        static fn (array $match): string => '<testsuite name="'.(declaresClass($root, $match[1]) ? '' : 'P\\').$match[1].'" file="'.testPath($root, $match[1]).'"',
        $xml,
    ) ?? $xml;

    $xml = preg_replace_callback(
        '/ class="(Tests\\\\[A-Za-z0-9_\\\\]+)"/',
        static fn (array $match): string => ' class="'.(declaresClass($root, $match[1]) ? '' : 'P\\').$match[1].'"',
        $xml,
    ) ?? $xml;

    file_put_contents($junit, str_replace('file="tests/', 'file="'.$root.'/tests/', $xml));
}

echo $code === 0 ? PHP_EOL.'OK (pest)'.PHP_EOL : PHP_EOL.'FAILURES!'.PHP_EOL;

exit($code);
