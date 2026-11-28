<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\Category;
use App\Models\City;
use App\Models\EventFeature;
use App\Models\Page;
use App\Models\Tag;
use App\Models\User;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Il registro di sicurezza del catalogo (categorie, tag, caratteristiche,
 * città, pagine): le azioni che non si annullano o che cambiano il sito per
 * tutti. Un posto solo per il pannello e per l'app della redazione; dalle
 * richieste dell'app la riga prende da sola `canale: app`.
 *
 * - `catalogo_voce_eliminata`: l'eliminazione è definitiva (nessuna delle
 *   cinque voci ha il cestino) e porta via in cascata quello che la usa, per
 *   esempio i tag degli eventi. La scrive `CatalogDeletionObserver`, così
 *   vale anche per l'eliminazione in gruppo della tabella.
 * - `catalogo_citta_modificata`: fuso, chiusura della notte, indirizzo e
 *   interruttori di una città cambiano tutto il sito di quella città; la
 *   riga tiene i campi cambiati con il valore di prima e quello nuovo.
 * - `tag_uniti`: il doppione sparisce e i suoi eventi passano all'altro.
 */
final class CatalogAudit
{
    /** Le sezioni come le chiama l'app (`section` delle rotte del catalogo). */
    private const SECTIONS = [
        Category::class => 'categories',
        Tag::class => 'tags',
        EventFeature::class => 'event_features',
        City::class => 'cities',
        Page::class => 'pages',
    ];

    /** Quanti id di eventi toccati si scrivono al massimo nella riga (il numero è sempre intero). */
    public const MAX_EVENT_IDS = 200;

    /** Le colonne di servizio che non dicono niente in una modifica. */
    private const IGNORED = ['updated_at', 'created_at'];

    private static bool $quiet = false;

    /**
     * Esegue senza scrivere l'eliminazione: l'unione dei tag elimina il
     * doppione e scrive la sua riga `tag_uniti`, che dice già tutto.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function quietly(Closure $callback): mixed
    {
        $previous = self::$quiet;
        self::$quiet = true;
        try {
            return $callback();
        } finally {
            self::$quiet = $previous;
        }
    }

    /**
     * Una voce del catalogo eliminata.
     *
     * @param  list<int>  $eventIds  gli eventi che la usavano (i tag)
     */
    public static function deleted(Model $record, array $eventIds = []): void
    {
        if (self::$quiet) {
            return;
        }
        $details = [
            'sezione' => self::SECTIONS[$record::class] ?? class_basename($record),
            'id' => $record->getKey(),
            'nome' => self::label($record),
            'slug' => $record->getAttribute('slug'),
        ];
        if ($record instanceof Tag) {
            $details['eventi_toccati'] = count($eventIds);
            $details['eventi'] = array_slice($eventIds, 0, self::MAX_EVENT_IDS);
        }
        SecurityLog::scrivi('catalogo_voce_eliminata', null, $details, self::actor());
    }

    /**
     * Una città appena salvata: i campi cambiati con il valore di prima e
     * quello nuovo (`getPrevious()`/`getChanges()` dell'ultimo salvataggio).
     * Niente se non è cambiato niente.
     */
    public static function cityChanged(City $city, ?User $actor = null): void
    {
        $changes = array_diff_key($city->getChanges(), array_flip(self::IGNORED));
        if ($changes === []) {
            return;
        }
        $before = (new City)->setRawAttributes($city->getPrevious());
        $fields = [];
        foreach (array_keys($changes) as $field) {
            $fields[$field] = ['prima' => self::plain($before->getAttribute($field)), 'dopo' => self::plain($city->getAttribute($field))];
        }
        SecurityLog::scrivi('catalogo_citta_modificata', null, [
            'citta_id' => $city->getKey(),
            'citta' => (string) $city->name,
            'campi' => $fields,
        ], $actor ?? self::actor());
    }

    /**
     * Due tag uniti: chi resta, chi sparisce e gli eventi spostati.
     *
     * @param  list<int>  $movedEventIds
     */
    public static function tagsMerged(Tag $keep, Tag $absorbed, array $movedEventIds): void
    {
        SecurityLog::scrivi('tag_uniti', null, [
            'tag_id' => $keep->getKey(),
            'tag' => (string) $keep->name,
            'assorbito_id' => $absorbed->getKey(),
            'assorbito' => (string) $absorbed->name,
            'eventi_spostati' => count($movedEventIds),
            'eventi' => array_slice($movedEventIds, 0, self::MAX_EVENT_IDS),
        ], self::actor());
    }

    private static function label(Model $record): string
    {
        $label = $record->getAttribute($record instanceof Page ? 'title' : 'name');

        return is_scalar($label) ? (string) $label : '';
    }

    /** Un valore leggibile nel registro: date e oggetti come testo, il resto com'è. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_object($value)) {
            return method_exists($value, 'toArray') ? $value->toArray() : (string) json_encode($value);
        }

        return $value;
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
