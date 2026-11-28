<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Le categorie (R-CT-01), come `CategoryResource`: colonne nome, categoria
 * padre, durata predefinita, «può risultare in corso», vita notturna,
 * eventi, attiva; filtri attiva e vita notturna; ordine predefinito
 * `sort_order`. Tre campi governano il motore temporale (durata, in corso,
 * vita notturna): l'observer ricalcola le date già salvate, qualunque strada
 * arrivi la modifica.
 */
final class CatalogCategories extends CatalogSection
{
    public function model(): string
    {
        return Category::class;
    }

    public function filters(): array
    {
        return ['is_active', 'is_nightlife'];
    }

    protected function listQuery(): Builder
    {
        return Category::query()->with('parent:id,name')->withCount('events');
    }

    protected function searchColumns(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name' => 'name', 'sort_order' => 'sort_order'];
    }

    protected function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }

    public function row(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            'id' => $record->id,
            'name' => (string) $record->name,
            'slug' => (string) $record->slug,
            'parent' => $record->parent === null ? null : ['id' => $record->parent->id, 'name' => (string) $record->parent->name],
            'icon' => $record->icon,
            'color' => $record->color,
            'sort_order' => (int) $record->sort_order,
            'default_duration_minutes' => $record->default_duration_minutes,
            'supports_ongoing' => (bool) $record->supports_ongoing,
            'is_nightlife' => (bool) $record->is_nightlife,
            'is_active' => (bool) $record->is_active,
            'events_count' => (int) ($record->events_count ?? 0),
            'web_path' => $this->webPath($record),
            'abilities' => $this->abilities($record, $user),
        ];
    }

    public function form(Model $record, User $user): array
    {
        $record = self::record($record);
        $record->loadMissing('parent:id,name')->loadCount('events');

        return [
            ...$this->row($record, $user),
            'parent_id' => $record->parent_id,
            ...CatalogContent::form($record, taxonomy: true),
        ];
    }

    protected function rules(?Model $record, bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($record?->getKey())],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id'), Rule::notIn(array_filter([$record?->getKey()]))],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            // La colonna è senza segno: nel pannello un numero negativo finisce in un errore del database.
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'supports_ongoing' => ['sometimes', 'boolean'],
            'is_nightlife' => ['sometimes', 'boolean'],
            ...CatalogContent::rules(taxonomy: true),
        ];
    }

    protected function attributes(): array
    {
        return [
            'name' => (string) __('admin.fields.name'),
            'slug' => (string) __('admin.fields.slug'),
            'parent_id' => (string) __('admin.fields.parent_category'),
            'icon' => (string) __('admin.fields.icon'),
            'color' => (string) __('admin.fields.color'),
            'sort_order' => (string) __('admin.fields.sort_order'),
            'is_active' => (string) __('admin.fields.is_active'),
            'default_duration_minutes' => (string) __('admin.fields.default_duration_minutes'),
            'supports_ongoing' => (string) __('admin.fields.supports_ongoing'),
            'is_nightlife' => (string) __('admin.fields.is_nightlife'),
            ...CatalogContent::attributes(),
        ];
    }

    protected function fill(Model $record, array $state, User $actor): void
    {
        $record = self::record($record);
        foreach (['name', 'slug', 'parent_id', 'icon', 'color', 'sort_order', 'default_duration_minutes'] as $field) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, self::blankToNull($state[$field]));
            }
        }
        // I valori predefiniti degli interruttori del modulo del pannello.
        foreach (['is_active' => true, 'supports_ongoing' => true, 'is_nightlife' => false] as $field => $default) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, (bool) $state[$field]);
            } elseif (! $record->exists) {
                $record->setAttribute($field, $default);
            }
        }
        CatalogContent::fill($record, $state, $actor);
    }

    /** La voce di questa sezione: un altro modello qui è un errore di chi chiama. */
    private static function record(Model $record): Category
    {
        if (! $record instanceof Category) {
            throw new \InvalidArgumentException('Atteso '.Category::class.', ricevuto '.$record::class.'.');
        }

        return $record;
    }
}
