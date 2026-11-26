<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Actions\MergeTagsAction;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * I tag (R-CT-02…04), come `TagResource`: colonne nome, categoria, eventi,
 * utilizzi, approvato; filtro approvato; ordine predefinito per nome.
 * «Approvato» è un campo del modulo (R-CT-03): lo scrive chi può modificare
 * il tag, cioè admin e super admin. `TagPolicy::approve` darebbe la stessa
 * cosa al moderatore, ma nel pannello non c'è un pulsante che la usi, e
 * nemmeno qui. L'unione dei doppioni è `MergeTagsAction`, la stessa del
 * pannello, con la stessa autorizzazione (`delete` sul tag che resta).
 */
final class CatalogTags extends CatalogSection
{
    public function __construct(private readonly MergeTagsAction $merge) {}

    public function model(): string
    {
        return Tag::class;
    }

    public function filters(): array
    {
        return ['is_approved'];
    }

    protected function listQuery(): Builder
    {
        return Tag::query()->with('category:id,name')->withCount('events');
    }

    protected function searchColumns(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name' => 'name', 'events_count' => 'events_count', 'usage_count' => 'usage_count'];
    }

    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function row(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            'id' => $record->id,
            'name' => (string) $record->name,
            'slug' => (string) $record->slug,
            'category' => $record->category === null ? null : ['id' => $record->category->id, 'name' => (string) $record->category->name],
            'events_count' => (int) ($record->events_count ?? 0),
            'usage_count' => (int) $record->usage_count,
            'is_approved' => (bool) $record->is_approved,
            'web_path' => $this->webPath($record),
            'abilities' => [
                ...$this->abilities($record, $user),
                'merge' => Gate::forUser($user)->allows('delete', $record),
            ],
        ];
    }

    public function form(Model $record, User $user): array
    {
        $record = self::record($record);
        $record->loadMissing('category:id,name')->loadCount('events');

        return [
            ...$this->row($record, $user),
            'category_id' => $record->category_id,
            'synonyms' => array_values(array_map('strval', is_array($record->synonyms) ? $record->synonyms : [])),
            ...CatalogContent::form($record, taxonomy: true),
        ];
    }

    /** Un tag per id, 404 se non c'è. */
    public function tag(int $id): Tag
    {
        return self::record($this->find($id));
    }

    /**
     * Unisce `absorbed` in `keep`: gli eventi passano, il nome diventa un
     * sinonimo, il doppione sparisce. Restituisce quanti eventi hanno
     * cambiato tag.
     */
    public function merge(Tag $keep, Tag $absorbed): int
    {
        return $this->merge->execute($keep, $absorbed);
    }

    protected function rules(?Model $record, bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('tags', 'slug')->ignore($record?->getKey())],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'is_approved' => ['sometimes', 'boolean'],
            'synonyms' => ['nullable', 'array', 'list'],
            // Il campo a etichette non manda voci vuote; qui una voce vuota si scarta invece di fallire.
            'synonyms.*' => ['nullable', 'string', 'max:255'],
            ...CatalogContent::rules(taxonomy: true),
        ];
    }

    protected function attributes(): array
    {
        return [
            'name' => (string) __('admin.fields.name'),
            'slug' => (string) __('admin.fields.slug'),
            'category_id' => (string) __('admin.fields.category'),
            'is_approved' => (string) __('admin.fields.is_approved'),
            'synonyms' => (string) __('admin.fields.synonyms'),
            'synonyms.*' => (string) __('admin.fields.synonyms'),
            ...CatalogContent::attributes(),
        ];
    }

    protected function fill(Model $record, array $state, User $actor): void
    {
        $record = self::record($record);
        foreach (['name', 'slug', 'category_id'] as $field) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, self::blankToNull($state[$field]));
            }
        }
        if (array_key_exists('is_approved', $state)) {
            $record->setAttribute('is_approved', (bool) $state['is_approved']);
        } elseif (! $record->exists) {
            $record->setAttribute('is_approved', false);
        }
        if (array_key_exists('synonyms', $state)) {
            $synonyms = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($state['synonyms']) ? $state['synonyms'] : [],
            ), static fn (string $value): bool => $value !== ''));
            // Il campo a etichette del pannello salva un elenco vuoto, non `null`.
            $record->setAttribute('synonyms', $synonyms);
        }
        CatalogContent::fill($record, $state, $actor);
    }

    /** La voce di questa sezione: un altro modello qui è un errore di chi chiama. */
    private static function record(Model $record): Tag
    {
        if (! $record instanceof Tag) {
            throw new \InvalidArgumentException('Atteso '.Tag::class.', ricevuto '.$record::class.'.');
        }

        return $record;
    }
}
