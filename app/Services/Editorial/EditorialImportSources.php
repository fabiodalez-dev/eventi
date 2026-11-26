<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Jobs\Import\ImportSourceJob;
use App\Models\Category;
use App\Models\City;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use App\Services\Import\ImportDriverFactory;
use App\Support\Import\ImportPreviewRows;
use App\Support\Import\ImportSourceRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Le sorgenti di import nell'app della redazione (R12, R-SY-01…04), come la
 * risorsa «Sorgenti di import» del pannello (`ImportSourceResource` e
 * `RunsRelationManager`): l'elenco con i suoi filtri, la riga, il modulo,
 * l'anteprima, «Esegui adesso» e lo storico delle esecuzioni.
 *
 * Le regole non stanno qui. Chi può fare che cosa lo dice `ImportSourcePolicy`;
 * le regole del modulo sono `ImportSourceRules` (usate anche dal pannello);
 * l'anteprima è `ImportPreviewRows::forSource` (la stessa della finestra del
 * pannello); l'esecuzione accoda lo stesso `ImportSourceJob` del pulsante.
 *
 * Le credenziali non escono mai, nemmeno cifrate: la riga dice solo se ci
 * sono (`has_credentials`). Si scrivono dal sito (decisione 6).
 *
 * Il pannello vede le sorgenti di tutte le città: anche l'app.
 */
final class EditorialImportSources
{
    /** L'unica colonna ordinabile della tabella del pannello. */
    public const SORTS = ['last_run_at'];

    /** Lo storico si ordina per data dell'esecuzione (`created_at`), predefinito dalla più recente. */
    public const RUN_SORTS = ['created_at'];

    /** Quante scelte dà la ricerca di un locale nel modulo. */
    public const VENUE_CHOICES = 20;

    public function __construct(private readonly ImportDriverFactory $drivers) {}

    // ------------------------------------------------------------------ elenco

    /**
     * Le sorgenti, dall'ultima eseguita, con i filtri del pannello: tipo,
     * attiva sì/no, «Import falliti» (attive e ferme su un errore, la stessa
     * condizione del riquadro del riepilogo). Il pannello non ha ricerca.
     *
     * @param  array{type?: string|null, active?: bool|null, failed?: bool}  $filters
     * @return LengthAwarePaginator<int, ImportSource>
     */
    public function sources(array $filters, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ImportSource::query()->with(['city:id,name', 'venue:id,name', 'defaultCategory:id,name']);

        if (($filters['type'] ?? null) !== null) {
            $query->where('type', $filters['type']);
        }
        if (($filters['active'] ?? null) !== null) {
            $query->where('is_active', $filters['active']);
        }
        if (($filters['failed'] ?? false) === true) {
            EditorialDashboardQuery::failedImportScope($query);
        }

        self::order($query, $sort, 'last_run_at');

        return $query->paginate($perPage, page: $page);
    }

    /** Una sorgente, con quello che la riga mostra. */
    public function source(int $id): ImportSource
    {
        return ImportSource::query()->with(['city:id,name,timezone', 'venue:id,name', 'defaultCategory:id,name'])->findOrFail($id);
    }

    /**
     * Una riga: le colonne della tabella del pannello, i campi del modulo
     * (senza le credenziali) e le azioni permesse. `runnable` è falso per i
     * tipi senza un lettore (`manual`, `json`…): il pannello per loro non
     * mostra «Anteprima» né «Esegui adesso».
     *
     * @return array<string, mixed>
     */
    public function row(ImportSource $source, User $user): array
    {
        $gate = Gate::forUser($user);
        $status = ImportRunStatus::tryFrom((string) $source->last_status);
        $runnable = $this->drivers->supports($source);
        $run = $runnable && $gate->allows('run', $source);
        $mapping = $source->mapping;

        return [
            'id' => $source->id,
            'city' => $source->city === null ? null : ['id' => $source->city->id, 'name' => $source->city->name],
            'venue' => $source->venue === null ? null : ['id' => $source->venue->id, 'name' => $source->venue->name],
            'type' => $source->type->value,
            'type_label' => $source->type->label(),
            'url' => $source->url,
            // Solo se ci sono: il segreto resta sul server, come nel modulo del pannello.
            'has_credentials' => filled($source->getRawOriginal('credentials')),
            'mapping' => is_array($mapping) && $mapping !== [] ? $mapping : null,
            'default_category' => $source->defaultCategory === null ? null : ['id' => $source->defaultCategory->id, 'name' => $source->defaultCategory->name],
            'is_active' => (bool) $source->is_active,
            'last_run_at' => $source->last_run_at?->toIso8601String(),
            // Una stringa libera per contratto: un valore che l'enum non conosce si mostra così com'è.
            'last_status' => $source->last_status,
            'last_status_label' => $status?->label() ?? $source->last_status,
            'last_error' => $source->last_error,
            'runnable' => $runnable,
            // La scheda del pannello dove si scrivono le credenziali.
            'web_path' => 'admin/import-sources/'.$source->id.'/edit',
            'abilities' => [
                'update' => $gate->allows('update', $source),
                'destroy' => $gate->allows('delete', $source),
                'preview' => $run,
                'run' => $run,
                'runs' => $gate->allows('view', $source),
            ],
        ];
    }

    // ------------------------------------------------------------------ modulo

    /**
     * Quello che serve al modulo per scegliere: i tipi (con chi ha un
     * lettore), le città e le categorie (tutte, come le scelte del pannello),
     * i valori predefiniti e i tetti. I locali si cercano a parte
     * (`venues()`): sono troppi per un elenco.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        $supported = ImportDriverFactory::supportedTypes();

        return [
            'types' => array_map(static fn (ImportSourceType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'runnable' => in_array($type->value, $supported, true),
            ], ImportSourceType::cases()),
            'cities' => City::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_active'])
                ->map(static fn (City $city): array => ['id' => $city->id, 'name' => $city->name, 'slug' => $city->slug, 'is_active' => (bool) $city->is_active])->values()->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->values()->all(),
            'defaults' => [
                // Come il pannello: la prima città per id, ICS, attiva.
                'city_id' => City::query()->orderBy('id')->value('id'),
                'type' => ImportSourceType::Ics->value,
                'is_active' => true,
            ],
            'limits' => [
                'max_url_length' => ImportSourceRules::MAX_URL_LENGTH,
                'max_mapping_entries' => ImportSourceRules::MAX_MAPPING_ENTRIES,
                'max_mapping_key_length' => ImportSourceRules::MAX_MAPPING_KEY_LENGTH,
                'max_mapping_value_length' => ImportSourceRules::MAX_MAPPING_VALUE_LENGTH,
            ],
        ];
    }

    /**
     * I locali per il campo «Locale», cercati per nome come la scelta del
     * pannello (tutti i locali, di ogni città).
     *
     * @return list<array{id: int, name: string, city: string|null}>
     */
    public function venues(?string $search): array
    {
        $query = Venue::query()->with('city:id,name')->select(['id', 'name', 'city_id'])->orderBy('name')->limit(self::VENUE_CHOICES);
        if ($search !== null) {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return $query->get()->map(static fn (Venue $venue): array => [
            'id' => $venue->id,
            'name' => $venue->name,
            'city' => $venue->city?->name,
        ])->values()->all();
    }

    /**
     * Crea o modifica una sorgente con i campi già validati. Le credenziali
     * non arrivano mai da qui (la richiesta le rifiuta): quelle salvate dal
     * sito restano come sono.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(?ImportSource $source, array $data): ImportSource
    {
        $source ??= new ImportSource(['is_active' => true, 'type' => ImportSourceType::Ics]);

        $fields = array_intersect_key($data, array_flip(['city_id', 'venue_id', 'type', 'default_category_id', 'url', 'mapping', 'is_active']));
        if (array_key_exists('mapping', $fields) && $fields['mapping'] === []) {
            $fields['mapping'] = null;
        }

        $source->fill($fields)->save();

        return $this->source((int) $source->getKey());
    }

    /**
     * L'anteprima della finestra del pannello: le prime date che entrerebbero,
     * nell'ora della città, oppure il motivo per cui il calendario non si legge.
     *
     * @return array{rows: list<array{when: string, title: string, where: string|null, notes: list<string>, cancelled: bool}>, error: string|null}
     */
    public function preview(ImportSource $source): array
    {
        return ImportPreviewRows::forSource($source);
    }

    /**
     * «Esegui adesso»: accoda lo stesso lavoro dell'esecuzione oraria, come
     * il pulsante del pannello. Non esegue nel processo della richiesta.
     */
    public function run(ImportSource $source): void
    {
        ImportSourceJob::dispatch((int) $source->getKey());
    }

    public function runnable(ImportSource $source): bool
    {
        return $this->drivers->supports($source);
    }

    // ------------------------------------------------------------------ storico

    /**
     * Le esecuzioni di una sorgente, dalla più recente, con il filtro
     * «Esito» del pannello. Solo lettura, come nel pannello.
     *
     * @return LengthAwarePaginator<int, ImportRun>
     */
    public function runs(ImportSource $source, ?string $status, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ImportRun::query()->where('import_source_id', $source->getKey());

        if ($status !== null) {
            $query->where('status', $status);
        }

        self::order($query, $sort, 'id');

        return $query->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function runRow(ImportRun $run): array
    {
        return [
            'id' => $run->id,
            'created_at' => $run->created_at?->toIso8601String(),
            'status' => $run->status,
            'status_label' => $run->runStatus()?->label() ?? $run->status,
            'created_count' => (int) $run->created_count,
            'updated_count' => (int) $run->updated_count,
            'unchanged_count' => (int) $run->unchanged_count,
            'excluded_count' => (int) $run->excluded_count,
            'cancelled_count' => (int) $run->cancelled_count,
            'error_count' => (int) $run->error_count,
            'message' => $run->message,
        ];
    }

    /**
     * L'ordinamento chiesto (`campo` o `-campo`), altrimenti quello del
     * pannello (decrescente); a parità, la riga più recente.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function order(Builder $query, ?string $sort, string $default): void
    {
        if ($sort !== null && $sort !== '') {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        } else {
            $query->orderBy($default, 'desc');
        }
        if ($default !== 'id' || ($sort !== null && $sort !== '')) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
        }
    }
}
