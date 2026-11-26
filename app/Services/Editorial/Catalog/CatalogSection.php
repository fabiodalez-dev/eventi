<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Una risorsa del gruppo «Contenuti» del pannello (categorie, tag,
 * caratteristiche, città, pagine) per l'app della redazione: elenco con
 * ricerca, filtri sì/no e ordinamento come la tabella di Filament, modulo,
 * salvataggio ed eliminazione.
 *
 * Le colonne, i filtri, l'ordinamento predefinito e le regole dei campi sono
 * quelli della risorsa del pannello, scritti una volta per sezione nelle
 * sottoclassi. Chi può fare che cosa lo dice la policy del modello, la
 * stessa che usa Filament. Ogni sottoclasse lavora su un modello solo
 * (`model()`) e lo controlla con `record()` prima di leggerne i campi.
 */
abstract class CatalogSection
{
    /** @return class-string<Model> */
    abstract public function model(): string;

    /**
     * La query dell'elenco con quello che la riga mostra (conteggi, relazioni).
     *
     * @return Builder<covariant Model>
     */
    abstract protected function listQuery(): Builder;

    /** @return list<string> le colonne della ricerca (`->searchable()` del pannello) */
    abstract protected function searchColumns(): array;

    /** @return array<string, string> campo di `sort` → colonna (`->sortable()` del pannello) */
    abstract protected function sorts(): array;

    /** @return array{0: string, 1: 'asc'|'desc'} l'ordinamento predefinito (`defaultSort`) */
    abstract protected function defaultSort(): array;

    /** @return list<string> i filtri sì/no (`TernaryFilter`) */
    public function filters(): array
    {
        return [];
    }

    /**
     * La riga dell'elenco, con le `abilities` calcolate dalla policy.
     *
     * @return array<string, mixed>
     */
    abstract public function row(Model $record, User $user): array;

    /**
     * Il modulo completo per chi può modificare.
     *
     * @return array<string, mixed>
     */
    abstract public function form(Model $record, User $user): array;

    /**
     * Le regole del modulo del pannello.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(?Model $record, bool $creating): array;

    /** @return array<string, string> i nomi dei campi come le etichette del pannello */
    abstract protected function attributes(): array;

    /**
     * I dati convalidati sul modello (nuovo o esistente), senza salvarlo.
     *
     * @param  array<string, mixed>  $state
     */
    abstract protected function fill(Model $record, array $state, User $actor): void;

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(array $filters, string $search, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->listQuery();

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function (Builder $inner) use ($like): void {
                foreach ($this->searchColumns() as $column) {
                    $inner->orWhere($column, 'like', $like);
                }
            });
        }

        foreach ($this->filters() as $key) {
            if (isset($filters[$key]) && in_array($filters[$key], ['0', '1'], true)) {
                $query->where($key, $filters[$key] === '1');
            }
        }

        [$column, $direction] = $this->sortOf($sort);
        $query->orderBy($column, $direction)->orderBy($query->getModel()->getQualifiedKeyName());

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /** La voce, 404 se non c'è. */
    public function find(int $id): Model
    {
        $class = $this->model();

        return $class::query()->findOrFail($id);
    }

    /**
     * Una voce nuova: le regole del modulo, poi il salvataggio.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor): Model
    {
        $class = $this->model();
        $record = new $class;
        $state = $this->validate($input, null, creating: true);

        return DB::transaction(function () use ($record, $state, $actor): Model {
            $this->fill($record, $state, $actor);
            $record->save();

            return $record->refresh();
        });
    }

    /**
     * Le chiavi ricevute: come il pannello, il modulo intero; l'app manda
     * comunque tutti i campi che mostra.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Model $record, array $input, User $actor): Model
    {
        $state = $this->validate($input, $record, creating: false);

        return DB::transaction(function () use ($record, $state, $actor): Model {
            $this->fill($record, $state, $actor);
            $record->save();

            return $record->refresh();
        });
    }

    /**
     * Elimina come `DeleteAction` del pannello. Una voce ancora usata (eventi
     * di una categoria, locali di una città: chiavi esterne «restrict») nel
     * pannello finisce in un errore del database; qui diventa un 409 con la
     * frase che lo spiega.
     */
    public function destroy(Model $record): void
    {
        try {
            DB::transaction(fn () => $record->delete());
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw new ApiException(ApiErrorCode::Conflict, (string) __('editorial-catalog.in_use'));
        }
    }

    /** @return array{update: bool, destroy: bool} */
    protected function abilities(Model $record, User $user): array
    {
        $gate = Gate::forUser($user);

        return ['update' => $gate->allows('update', $record), 'destroy' => $gate->allows('delete', $record)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validate(array $input, ?Model $record, bool $creating): array
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $this->rules($record, $creating), [], $this->attributes())->validate();

        return $validated;
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    private function sortOf(string $sort): array
    {
        $field = ltrim($sort, '-');
        $columns = $this->sorts();
        if ($field === '' || ! array_key_exists($field, $columns)) {
            return $this->defaultSort();
        }

        return [$columns[$field], str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }

    protected static function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
