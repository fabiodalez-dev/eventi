<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tag;
use App\Models\User;
use App\Support\Catalog\CatalogAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
 *   lascerebbe eventi senza tag e un doppione ancora vivo; dentro, i due tag
 *   sono bloccati e riletti, così due unioni incrociate non li perdono
 *   entrambi. L'unione va nel registro di sicurezza (`tag_uniti`).
 *
 * Chi unisce elimina il tag assorbito: serve `delete` su **tutti e due**,
 * non solo su quello da cui parte l'azione (`allows`/`authorize`, la stessa
 * regola per il pannello e per l'app).
 */
final class MergeTagsAction
{
    /** Vero se la persona può eliminare sia il tag che resta sia quello assorbito. */
    public static function allows(User $user, Tag $keep, Tag $absorbed): bool
    {
        $gate = Gate::forUser($user);

        return $gate->allows('delete', $keep) && $gate->allows('delete', $absorbed);
    }

    /** @throws AuthorizationException */
    public static function authorize(User $user, Tag $keep, Tag $absorbed): void
    {
        $gate = Gate::forUser($user);
        $gate->authorize('delete', $keep);
        $gate->authorize('delete', $absorbed);
    }

    /**
     * @return int Quanti eventi hanno cambiato tag.
     *
     * @throws ValidationException se uno dei due tag non c'è più
     */
    public function execute(Tag $keep, Tag $absorbed): int
    {
        if ($keep->is($absorbed)) {
            return 0;
        }

        return DB::transaction(function () use ($keep, $absorbed): int {
            /*
             * Le due righe bloccate in ordine di id, sempre lo stesso: «A in
             * B» e «B in A» lanciate insieme si mettono in fila invece di
             * eliminarsi a vicenda, e la seconda trova uno dei due tag già
             * sparito.
             */
            $locked = Tag::query()->whereKey([$keep->getKey(), $absorbed->getKey()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedKeep = $locked->get($keep->getKey());
            $lockedAbsorbed = $locked->get($absorbed->getKey());
            if (! $lockedKeep instanceof Tag || ! $lockedAbsorbed instanceof Tag) {
                throw ValidationException::withMessages(['absorbed_id' => __('admin.notifications.merge_gone')]);
            }
            $keep->setRawAttributes($lockedKeep->getAttributes(), true);
            $absorbed->setRawAttributes($lockedAbsorbed->getAttributes(), true);

            $eventIds = DB::table('event_tag')
                ->where('tag_id', $absorbed->getKey())
                ->whereNotIn('event_id', DB::table('event_tag')
                    ->where('tag_id', $keep->getKey())
                    ->pluck('event_id'))
                ->orderBy('event_id')
                ->pluck('event_id')
                ->map(fn (mixed $id): int => (int) $id)
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
            /* La riga `tag_uniti` dice già tutto: niente «voce eliminata» per il doppione. */
            CatalogAudit::quietly(fn () => $absorbed->delete());

            $keep->usage_count = $keep->events()->count();
            $keep->save();

            CatalogAudit::tagsMerged($keep, $absorbed, $eventIds);

            return count($eventIds);
        });
    }
}
