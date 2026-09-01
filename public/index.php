<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
 * Prima dell'autoloader: il minimo indispensabile perché un'installazione
 * nuova possa cominciare (D42, punto 1).
 *
 * Tutto ciò che segue è sorvegliato dal marcatore di installazione: su un
 * sistema già installato il file c'è, e questo blocco costa una sola
 * `file_exists()` per richiesta.
 */
if (! file_exists(__DIR__.'/../storage/app/private/install.lock')) {
    /*
     * Senza `vendor/` non parte niente — non un installer alternativo, non
     * una pagina di errore: la riga `require vendor/autoload.php` qui sotto
     * sarebbe un errore fatale, cioè una pagina bianca. Questo non è un
     * secondo installer: è un cartello con sopra il comando da dare.
     */
    if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 3600');

        exit('<!DOCTYPE html><html lang="it"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Dipendenze mancanti</title>'
            .'<style>body{margin:0;padding:1.5rem;background:#faf9f7;color:#2b2436;'
            .'font:1rem/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}'
            .'main{max-width:40rem;margin:0 auto}h1{font-size:1.375rem;line-height:1.25}'
            .'pre{background:#efedea;border-radius:.5rem;padding:.75rem;overflow-x:auto}'
            .'@media(prefers-color-scheme:dark){body{background:#191622;color:#efedf4}'
            .'pre{background:#241f30}}</style></head><body><main>'
            .'<h1>Mancano le dipendenze di PHP</h1>'
            .'<p>La cartella <code>vendor/</code> non c&rsquo;&egrave;, quindi l&rsquo;applicazione '
            .'non pu&ograve; avviarsi. Eseguila una volta sola, nella cartella dell&rsquo;applicazione:</p>'
            .'<pre><code>composer install --no-dev --optimize-autoloader</code></pre>'
            .'<p>Poi ricarica questa pagina: la procedura di installazione parte da s&eacute;.</p>'
            .'</main></body></html>');
    }

    /*
     * Il `.env` e la chiave applicativa devono esistere **prima** che il
     * wizard risponda: senza `APP_KEY` non ci sono sessioni cifrate né token
     * CSRF, e la prima schermata dell'installer morirebbe sul primo modulo.
     * La chiave è base64 di 32 byte casuali, la stessa forma che produce
     * `php artisan key:generate --show`.
     */
    $envPath = __DIR__.'/../.env';

    if (! file_exists($envPath) && file_exists(__DIR__.'/../.env.example')) {
        @copy(__DIR__.'/../.env.example', $envPath);
        @chmod($envPath, 0600);
    }

    if (is_file($envPath) && is_writable($envPath)) {
        $env = (string) @file_get_contents($envPath);

        if (preg_match('/^APP_KEY[ \t]*=[ \t]*\S/m', $env) !== 1) {
            $line = 'APP_KEY=base64:'.base64_encode(random_bytes(32));

            $env = preg_match('/^APP_KEY[ \t]*=.*$/m', $env) === 1
                ? preg_replace_callback('/^APP_KEY[ \t]*=.*$/m', static fn (): string => $line, $env, 1)
                : rtrim($env, "\n")."\n".$line."\n";

            /*
             * Scrittura atomica anche qui: file temporaneo nella stessa
             * directory e `rename()`. Un `.env` interrotto a metà è un sito
             * che non riparte, e questo codice gira proprio quando non c'è
             * ancora niente che possa dirlo.
             */
            $temporary = @tempnam(dirname($envPath), '.env-');

            if ($temporary !== false && @file_put_contents($temporary, (string) $env) !== false) {
                @chmod($temporary, 0600);
                @rename($temporary, $envPath);
            } elseif ($temporary !== false) {
                @unlink($temporary);
            }
        }
    }
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
