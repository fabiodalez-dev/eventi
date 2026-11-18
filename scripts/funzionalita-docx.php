<?php

declare(strict_types=1);

/*
 * Genera il documento Word delle funzionalità da docs/FUNZIONALITA.md.
 *
 *   php scripts/funzionalita-docx.php [destinazione.docx]
 *
 * Il Markdown nel repository è la fonte: si aggiorna lì, insieme alla PR che
 * aggiunge o cambia una funzione, e il .docx da mandare a operatori e
 * investitori si rigenera con questo script. Di norma finisce sulla
 * Scrivania con il nome del documento originale.
 *
 * Usa `textutil` di macOS per passare da HTML a .docx: nessuna dipendenza in
 * più nel progetto.
 */

use League\CommonMark\CommonMarkConverter;

require __DIR__.'/../vendor/autoload.php';

$source = __DIR__.'/../docs/FUNZIONALITA.md';
$target = $argv[1] ?? getenv('HOME').'/Desktop/inCitta Funzionalita per ruoli.docx';

$markdown = (string) file_get_contents($source);
// Le note per chi aggiorna il file non vanno nel documento.
$markdown = (string) preg_replace('/<!--.*?-->\s*/s', '', $markdown);
$body = (new CommonMarkConverter)->convert($markdown)->getContent();

$html = <<<HTML
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>inCittà — Funzionalità della piattaforma</title>
<style>
body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.45; color: #1f2328; }
h1 { font-size: 22pt; color: #b4232c; margin-bottom: 2pt; }
h2 { font-size: 15pt; color: #b4232c; margin-top: 18pt; border-bottom: 1px solid #e5e5e5; }
h3 { font-size: 12pt; margin-top: 12pt; }
li { margin-bottom: 4pt; }
em { color: #57606a; }
</style></head><body>{$body}</body></html>
HTML;

$tmp = tempnam(sys_get_temp_dir(), 'funzionalita').'.html';
file_put_contents($tmp, $html);
if (! str_ends_with($target, '.docx')) {
    fwrite(STDERR, "La destinazione deve essere un file .docx.\n");
    exit(1);
}
// Argomenti in array: nessuna shell in mezzo, quindi niente da interpretare nei percorsi.
// nosemgrep: exec-use
$process = proc_open(['textutil', '-convert', 'docx', '-output', $target, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$code = is_resource($process) ? proc_close($process) : 1;
unlink($tmp);

if ($code !== 0) {
    fwrite(STDERR, "textutil non è riuscito a creare il documento (codice {$code}).\n");
    exit(1);
}

echo "Creato: {$target}\n";
