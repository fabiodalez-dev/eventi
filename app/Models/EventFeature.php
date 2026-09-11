<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ContentVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EventFeature extends Model
{
    protected $fillable = ['name', 'slug', 'group', 'icon', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $feature): void {
            $feature->slug = $feature->slug ?: Str::slug($feature->name).'-'.Str::lower(Str::random(6));
        });
        $invalidate = function (): void {
            Cache::forget('event-features:catalog:v2');
            foreach (City::query()->pluck('id') as $id) {
                ContentVersion::bump((int) $id);
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /** @return array<string, array<int, string>> */
    public static function choices(): array
    {
        $options = [];
        foreach (static::query()->where('is_active', true)->where('is_system', false)->orderBy('group')->orderBy('sort_order')->orderBy('name')->get() as $feature) {
            $options[$feature->group][$feature->id] = $feature->name;
        }

        return $options;
    }
}
