<?php

namespace App\Models;

use App\Enums\ConsentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConsentScript extends Model
{
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
