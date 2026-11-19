<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Riscrive le descrizioni salvate con gli a capo dentro il testo.
 *
 * Le descrizioni importate (Facebook, testo incollato) sono finite in archivio
 * come `<p>riga uno\nriga due</p>`: il browser legge quell'a capo come uno
 * spazio. Da oggi `Description::render()` li mostra già come `<br>`, quindi il
 * sito è a posto anche senza questo comando; qui si sistema l'archivio, così
 * l'editor dei pannelli e ogni altra lettura trovano lo stesso testo.
 *
 * **Per prudenza non scrive niente se non glielo si chiede.** Senza `--write`
 * conta e mostra qualche esempio. In produzione si lancia con `--write` solo
 * dopo `backup:run --only-db`. La scrittura passa dalla query, non dal modello:
 * non è una modifica di chi gestisce l'evento, non deve rimandarlo in
 * redazione né comparire nel registro attività.
 */
class NormalizeDescriptionBreaks extends Command
{
    protected $signature = 'descriptions:normalize-breaks
        {--dry-run : Conta e mostra gli esempi senza scrivere (è il comportamento predefinito)}
        {--write : Salva le descrizioni corrette}
        {--examples=3 : Quanti esempi mostrare per tabella}';

    protected $description = 'Trasforma in <br> gli a capo scritti dentro le descrizioni HTML';

    /** @var array<string, string> tabella => colonna */
    private const COLUMNS = [
        'events' => 'description',
        'venues' => 'description',
        'organizers' => 'description',
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('write') && ! (bool) $this->option('dry-run');
        $examples = max(0, (int) $this->option('examples'));

        foreach (self::COLUMNS as $table => $column) {
            $found = 0;
            $shown = 0;

            DB::table($table)
                ->select(['id', $column])
                ->where($column, 'like', '%<%')
                ->where($column, 'like', "%\n%")
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $column, $write, $examples, &$found, &$shown): void {
                    foreach ($rows as $row) {
                        $before = (string) $row->{$column};
                        $after = Description::breakLines($before);
                        if ($after === $before) {
                            continue;
                        }
                        $found++;
                        if ($shown < $examples) {
                            $shown++;
                            $this->line(sprintf('%s #%d', $table, $row->id));
                            $this->line('  prima: '.str_replace("\n", '\n', mb_substr($before, 0, 160)));
                            $this->line('  dopo:  '.mb_substr($after, 0, 160));
                        }
                        if ($write) {
                            DB::table($table)->where('id', $row->id)->update([$column => $after]);
                        }
                    }
                });

            $this->info(sprintf('%s: %d %s', $table, $found, $write ? 'corrette' : 'da correggere'));
        }

        if (! $write) {
            $this->comment('Prova a vuoto: niente è stato salvato. Per scrivere: --write (dopo il backup del database).');
        }

        return self::SUCCESS;
    }
}
