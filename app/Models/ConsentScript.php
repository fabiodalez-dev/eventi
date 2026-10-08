<?php

namespace App\Models;

use App\Enums\ConsentCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConsentScript extends Model
{
    /**
     * Gli script attivi, nell'ordine di pagina, letti dalla cache.
     *
     * Ogni pagina pubblica li chiedeva due volte al database (una per il
     * banner, una per inserirli): la revisione cambia a ogni salvataggio, e
     * una chiave che la contiene si invalida da sola.
     *
     * @return Collection<int, self>
     */
    public static function enabledScripts(): Collection
    {
        $revision = (string) Cache::get('consent_scripts_revision', '0');

        /* In cache vanno gli attributi, non i modelli: la cache di Laravel 13
           non ricostruisce oggetti di classi non dichiarate, e un modello
           serializzato tornerebbe come `__PHP_Incomplete_Class`. */
        $rows = Cache::remember('consent_scripts:'.$revision, now()->addDay(), fn (): array => static::query()
            ->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (self $script): array => $script->getAttributes())->all());

        /** @var Collection<int, self> $scripts */
        $scripts = static::hydrate($rows);

        return $scripts;
    }

    protected $fillable = ['name', 'category', 'src', 'code', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['category' => ConsentCategory::class, 'enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forever('consent_scripts_revision', (string) Str::uuid()));
        static::deleted(fn () => Cache::forever('consent_scripts_revision', (string) Str::uuid()));
    }
}
