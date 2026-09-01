<?php

declare(strict_types=1);

namespace App\Services\Installer;

use RuntimeException;

/**
 * Scrive il file `.env` (D42, punto 3).
 *
 * Tre regole, tutte imparate da guasti veri:
 *
 * 1. **Una scrittura sola, atomica.** Il contenuto completo si costruisce in
 *    memoria a partire dal modello `.env.example`, si scrive su un file
 *    temporaneo *nella stessa directory* — perché `rename()` è atomico solo
 *    dentro lo stesso filesystem — e poi si rinomina. Nessun momento in cui il
 *    `.env` esiste a metà: se la richiesta muore, il file di prima è intatto.
 * 2. **I valori si quotano con criterio.** Uno spazio, un `#`, un apice o un
 *    `$` scritti nudi cambiano il significato della riga: un cancelletto apre
 *    un commento e tronca il valore, un dollaro innesca l'interpolazione. Il
 *    sintomo — una password che non funziona più — arriva giorni dopo e non
 *    assomiglia alla causa.
 * 3. **Le sostituzioni non passano da un testo di rimpiazzo.** La riga nuova si
 *    costruisce e si mette al posto di quella vecchia: nel testo di rimpiazzo
 *    di `preg_replace` una password che contiene `$1` verrebbe letta come
 *    riferimento a un gruppo di cattura e sparirebbe.
 * 4. **Si passa una volta sola sulle righe di partenza, mai su quelle già
 *    scritte.** Un valore può contenere un a capo — la richiesta arriva da un
 *    modulo pubblico, e nessuna validazione lo vieta — e quindi occupare più
 *    righe del file. Cercando la chiave successiva nel testo *già sostituito*,
 *    la ricerca finiva dentro quel valore: la riga infilata nella password
 *    veniva scambiata per la dichiarazione da aggiornare, gli apici restavano
 *    aperti e il `.env` diventava illeggibile per Dotenv, cioè l'applicazione
 *    non si avviava più — nemmeno per rifare l'installazione.
 */
class EnvWriter
{
    public function __construct(
        private readonly string $path,
        private readonly string $templatePath,
    ) {}

    /**
     * Quota un valore per il file `.env`.
     *
     * Sono quotati e sottoposti a escape solo i valori che contengono un
     * carattere che il parser interpreterebbe: spazi e a capo, `#`, `=`,
     * i due tipi di apice, la barra rovescia, il dollaro e il backtick.
     * Tutto il resto resta nudo, che è il modo in cui un `.env` si legge.
     */
    public function quoteValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/[\s#=\x22\x27\\\\$`!]/', $value) === 1) {
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }

    /**
     * Costruisce il contenuto del `.env` dal modello, sostituendo le chiavi
     * indicate e aggiungendo in coda quelle che il modello non prevede.
     *
     * Le ~76 variabili non toccate restano ai valori di `.env.example`, che in
     * questo progetto è già la configurazione di produzione corretta: ogni
     * integrazione esterna nasce spenta, e «vuoto = spento» è documentato lì.
     *
     * @param  array<string, string>  $values
     */
    public function render(array $values): string
    {
        $template = @file_get_contents($this->templatePath);

        if ($template === false) {
            throw new RuntimeException('Modello .env non leggibile: '.$this->templatePath);
        }

        [$template, $remaining] = $this->substitute($template, $values);

        if ($remaining !== []) {
            $extra = '';

            foreach ($remaining as $key => $value) {
                $extra .= $key.'='.$this->quoteValue($value)."\n";
            }

            $template = rtrim($template, "\n")."\n\n".$extra;
        }

        return rtrim($template, "\n")."\n";
    }

    /**
     * Scrive il file in modo atomico e lo chiude a chiave: `chmod 600`, perché
     * contiene la password del database e su una shared hosting il `.env` di
     * un vicino non deve essere leggibile.
     *
     * @param  array<string, string>  $values
     */
    public function write(array $values): void
    {
        $this->putAtomically($this->render($values));
    }

    /**
     * Aggiorna singole chiavi del `.env` già esistente, lasciando intatto tutto
     * il resto. È la scrittura che serve al punto d'ingresso per `APP_KEY`.
     *
     * @param  array<string, string>  $values
     */
    public function update(array $values): void
    {
        $current = @file_get_contents($this->path);

        if ($current === false) {
            throw new RuntimeException('File .env non leggibile: '.$this->path);
        }

        [$current, $remaining] = $this->substitute($current, $values);

        foreach ($remaining as $key => $value) {
            $current = rtrim($current, "\n")."\n".$key.'='.$this->quoteValue($value)."\n";
        }

        $this->putAtomically($current);
    }

    /**
     * Richiude il file a fine installazione. `EnvWriter` scrive già con
     * `0600`, ma il `.env` può essere arrivato da un'altra strada — copiato a
     * mano, caricato via FTP — e a quel punto contiene la password del
     * database con i permessi che gli ha dato il caricamento.
     */
    public function secure(): void
    {
        if ($this->exists()) {
            @chmod($this->path, 0600);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * `.env` scrivibile? Se non esiste ancora, conta la directory che lo
     * ospiterà: l'installer esiste per crearlo.
     */
    public function isWritable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /**
     * Riscrive le dichiarazioni delle chiavi indicate, **una passata sola** sul
     * testo di partenza.
     *
     * La passata unica è la parte che conta. Cercando una chiave alla volta nel
     * testo che le sostituzioni precedenti hanno già modificato, la ricerca
     * finirebbe dentro un valore che occupa più righe — e una password con un a
     * capo e dentro `DB_HOST=…` non è fantascienza: il modulo è pubblico e
     * nessuna regola vieta l'a capo. Quella riga verrebbe scambiata per la
     * dichiarazione da aggiornare, la stringa fra apici resterebbe aperta e il
     * file diventerebbe illeggibile per Dotenv, cioè un'applicazione che non
     * parte più. Qui ogni riga si guarda com'era, non com'è diventata.
     *
     * Il primo `KEY=` incontrato vince, commentato o no: `.env.example` tiene
     * spente con un cancelletto le variabili che di norma restano al valore
     * predefinito, e riaccenderle è esattamente ciò che serve.
     *
     * @param  array<string, string>  $values
     * @return array{0: string, 1: array<string, string>} il testo riscritto e le chiavi che il testo non conteneva
     */
    private function substitute(string $contents, array $values): array
    {
        $remaining = $values;
        $lines = preg_split("/\r\n|\n|\r/", $contents);

        if ($lines === false) {
            return [$contents, $values];
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/^(?:#[ \t]*)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];

            if (! array_key_exists($key, $remaining)) {
                continue;
            }

            $lines[$index] = $key.'='.$this->quoteValue($remaining[$key]);
            unset($remaining[$key]);
        }

        return [implode("\n", $lines), $remaining];
    }

    private function putAtomically(string $contents): void
    {
        $directory = dirname($this->path);
        $temporary = @tempnam($directory, '.env-');

        if ($temporary === false) {
            throw new RuntimeException('Directory non scrivibile: '.$directory);
        }

        if (@file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);

            throw new RuntimeException('Scrittura del .env non riuscita: '.$this->path);
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('Sostituzione del .env non riuscita: '.$this->path);
        }

        @chmod($this->path, 0600);
    }
}
