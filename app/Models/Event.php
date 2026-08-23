<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VerificationStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Event extends Model implements HasMedia
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasSlug;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'venue_id',
        'category_id',
        'created_by',
        'organizer_name',
        'organizer_url',
        'title',
        'slug',
        'subtitle',
        'description',
        'short_description',
        'poster',
        'gallery',
        'price_type',
        'price_min',
        'price_max',
        'currency',
        'price_notes',
        'ticket_url',
        'booking_required',
        'booking_url',
        'booking_phone',
        'age_restriction',
        'language',
        'is_outdoor',
        'custom_location',
        'external_links',
        'source',
        'source_ref',
        'verification_status',
        'status',
        'rejection_reason',
        'is_featured',
        'featured_until',
        'editorial_score',
        'published_at',
        'seo',
        'views_count',
        'saves_count',
    ];

    /**
     * Lo slug è unico per città, non globalmente: la stessa serata può esistere
     * a Padova e a Vicenza con lo stesso titolo. Il vincolo corrispondente è
     * l'indice unico `(city_id, slug)` della tabella.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->extraScope(fn (Builder $query) => $query->where('city_id', $this->city_id));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('event')
            ->logOnly(['status', 'verification_status', 'published_at', 'rejection_reason', 'is_featured', 'editorial_score'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('poster')->singleFile();
        $this->addMediaCollection('gallery');
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'event_tag');
    }

    /** @return HasMany<EventOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /** @return HasMany<EventRecurrence, $this> */
    public function recurrences(): HasMany
    {
        return $this->hasMany(EventRecurrence::class);
    }

    /** @return HasManyThrough<Lineup, EventOccurrence, $this> */
    public function lineups(): HasManyThrough
    {
        return $this->hasManyThrough(Lineup::class, EventOccurrence::class, 'event_id', 'occurrence_id');
    }

    /** @return HasMany<EventViewDaily, $this> */
    public function dailyViews(): HasMany
    {
        return $this->hasMany(EventViewDaily::class);
    }

    /** @return HasMany<EventSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class);
    }

    /** @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfStatus(Builder $query, EventStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeInCity(Builder $query, City|int $city): void
    {
        $query->where('city_id', $city instanceof City ? $city->getKey() : $city);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfCategory(Builder $query, Category|int $category): void
    {
        $query->where('category_id', $category instanceof Category ? $category->getKey() : $category);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeAtVenue(Builder $query, Venue|int $venue): void
    {
        $query->where('venue_id', $venue instanceof Venue ? $venue->getKey() : $venue);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfSource(Builder $query, EventSource $source): void
    {
        $query->where('source', $source);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeFree(Builder $query): void
    {
        $query->where('price_type', PriceType::Free);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeWithTag(Builder $query, Tag|int $tag): void
    {
        $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tag instanceof Tag ? $tag->getKey() : $tag));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'source' => EventSource::class,
            'verification_status' => VerificationStatus::class,
            'status' => EventStatus::class,
            'gallery' => 'array',
            'custom_location' => 'array',
            'external_links' => 'array',
            'seo' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'booking_required' => 'boolean',
            'is_outdoor' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'editorial_score' => 'integer',
            'published_at' => 'datetime',
            'views_count' => 'integer',
            'saves_count' => 'integer',
        ];
    }
}
