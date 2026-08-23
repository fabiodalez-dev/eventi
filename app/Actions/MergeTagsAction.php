<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Unisce due tag doppioni (§9.2, «Tag — con merge dei duplicati»).
 *
 * Il tag **assorbito** sparisce; quello che resta eredita i suoi eventi, ne
 * conserva il nome fra i sinonimi — così una ricerca per la vecchia parola
 * continua a funzionare — e si ritrova il conteggio d'uso aggiornato.
 *
 * Due precauzioni che valgono più della semplicità del codice:
 *
 * - la pivot `event_tag` ha chiave primaria composta, quindi un evento che
 *   porta **entrambi** i tag farebbe fallire un `UPDATE` diretto: le righe da
 *   spostare vengono scelte escludendo quelle già presenti sul superstite;
 * - tutto avviene in una transazione, perché un merge interrotto a metà
 *   lascerebbe eventi senza tag e un doppione ancora vivo.
 */
final class MergeTagsAction
{
    /**
     * @return int Quanti eventi hanno cambiato tag.
     */
    public function execute(Tag $keep, Tag $absorbed): int
    {
        if ($keep->is($absorbed)) {
            return 0;
        }

        return DB::transaction(function () use ($keep, $absorbed): int {
            $eventIds = DB::table('event_tag')
                ->where('tag_id', $absorbed->getKey())
                ->whereNotIn('event_id', DB::table('event_tag')
                    ->where('tag_id', $keep->getKey())
                    ->pluck('event_id'))
                ->pluck('event_id')
                ->all();

            if ($eventIds !== []) {
                DB::table('event_tag')
                    ->where('tag_id', $absorbed->getKey())
                    ->whereIn('event_id', $eventIds)
                    ->update(['tag_id' => $keep->getKey()]);
            }

            $synonyms = $keep->synonyms ?? [];
            $synonyms[] = $absorbed->name;

            foreach ($absorbed->synonyms ?? [] as $synonym) {
                $synonyms[] = $synonym;
            }

            $keep->synonyms = array_values(array_unique($synonyms));

            $absorbed->events()->detach();
            $absorbed->delete();

            $keep->usage_count = $keep->events()->count();
            $keep->save();

            return count($eventIds);
        });
    }
}
