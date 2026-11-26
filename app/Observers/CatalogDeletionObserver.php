<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tag;
use App\Support\Catalog\CatalogAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * L'eliminazione di una voce del catalogo (categoria, tag, caratteristica,
 * città, pagina) nel registro di sicurezza, da qualunque parte arrivi: la
 * pagina di modifica del pannello, l'eliminazione in gruppo della tabella
 * (`BulkActions::delete()`, che elimina riga per riga), un comando.
 *
 * Per un tag gli eventi che lo portavano si leggono prima, perché la riga
 * di `event_tag` se ne va in cascata. La riga si scrive solo a eliminazione
 * riuscita: una voce ancora usata (chiave esterna «restrict») non lascia
 * traccia di un'eliminazione che non c'è stata.
 */
final class CatalogDeletionObserver
{
    /** @var WeakMap<Model, list<int>>|null */
    private static ?WeakMap $events = null;

    public function deleting(Model $record): void
    {
        if ($record instanceof Tag) {
            self::$events ??= new WeakMap;
            self::$events[$record] = array_values(array_map(
                fn (mixed $id): int => (int) $id,
                DB::table('event_tag')->where('tag_id', $record->getKey())->orderBy('event_id')->pluck('event_id')->all(),
            ));
        }
    }

    public function deleted(Model $record): void
    {
        $events = self::$events !== null && isset(self::$events[$record]) ? self::$events[$record] : [];
        CatalogAudit::deleted($record, $events);
    }
}
