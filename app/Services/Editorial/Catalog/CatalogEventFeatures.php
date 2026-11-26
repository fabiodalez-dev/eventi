<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Models\EventFeature;
use App\Models\User;
use App\Support\PracticalIcons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Le caratteristiche degli eventi, «Prima di andare» nel pannello (R-CT-05),
 * come `EventFeatureResource`: solo admin e super admin
 * (`EventFeaturePolicy`). Le voci di sistema (tessera, accessibilità) si
 * accendono dal dato dell'evento: non si eliminano (la policy dice no) e il
 * loro «Disponibile» non si cambia (nel pannello il campo è spento e non si
 * salva).
 */
final class CatalogEventFeatures extends CatalogSection
{
    public function model(): string
    {
        return EventFeature::class;
    }

    protected function listQuery(): Builder
    {
        return EventFeature::query();
    }

    protected function searchColumns(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name' => 'name', 'group' => 'group'];
    }

    protected function defaultSort(): array
    {
        return ['group', 'asc'];
    }

    public function row(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            'id' => $record->id,
            'name' => (string) $record->name,
            'group' => (string) $record->group,
            'icon' => PracticalIcons::safe($record->icon),
            'description' => $record->description,
            'sort_order' => (int) $record->sort_order,
            'is_active' => (bool) $record->is_active,
            'is_system' => (bool) $record->is_system,
            'abilities' => $this->abilities($record, $user),
        ];
    }

    public function form(Model $record, User $user): array
    {
        $record = self::record($record);

        return $this->row($record, $user);
    }

    protected function rules(?Model $record, bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'group' => ['required', 'string', Rule::in(EventFeature::GROUPS)],
            'icon' => ['required', 'string', Rule::in(array_keys(PracticalIcons::options()))],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function attributes(): array
    {
        return [
            'name' => 'Nome visibile',
            'group' => 'Gruppo',
            'icon' => 'Icona',
            'description' => 'Spiegazione breve',
            'sort_order' => 'Ordine',
            'is_active' => 'Disponibile',
        ];
    }

    protected function fill(Model $record, array $state, User $actor): void
    {
        $record = self::record($record);
        foreach (['name', 'group', 'icon', 'description'] as $field) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, self::blankToNull($state[$field]));
            }
        }
        if (array_key_exists('sort_order', $state)) {
            $record->setAttribute('sort_order', (int) ($state['sort_order'] ?? 0));
        }
        if (! $record->is_system && array_key_exists('is_active', $state)) {
            $record->setAttribute('is_active', (bool) $state['is_active']);
        } elseif (! $record->exists) {
            $record->setAttribute('is_active', true);
        }
    }

    /** La voce di questa sezione: un altro modello qui è un errore di chi chiama. */
    private static function record(Model $record): EventFeature
    {
        if (! $record instanceof EventFeature) {
            throw new \InvalidArgumentException('Atteso '.EventFeature::class.', ricevuto '.$record::class.'.');
        }

        return $record;
    }
}
