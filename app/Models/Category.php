<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEditorialContent;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Category extends Model
{
    use HasEditorialContent;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasSlug;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'color',
        'sort_order',
        'is_active',
        'parent_id',
        'default_duration_minutes',
        'supports_ongoing',
        'is_nightlife',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<ImportSource, $this> */
    public function importSources(): HasMany
    {
        return $this->hasMany(ImportSource::class, 'default_category_id');
    }

    /** @return MorphMany<Follow, $this> */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeNightlife(Builder $query): void
    {
        $query->where('is_nightlife', true);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeSupportingOngoing(Builder $query): void
    {
        $query->where('supports_ongoing', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'default_duration_minutes' => 'integer',
            'supports_ongoing' => 'boolean',
            'is_nightlife' => 'boolean',
        ];
    }
}
