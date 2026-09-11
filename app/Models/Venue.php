<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsAccessibilityProfile;
use App\Casts\AsFacts;
use App\Casts\AsTransitGuide;
use App\Enums\VenuePlan;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\Concerns\HasEditorialContent;
use App\Models\Concerns\HasImageVariants;
use App\Models\Concerns\HasSafeEditorContent;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Coordinate: il costruttore della libreria è `new Point($lat, $lng, 0)` —
 * latitudine prima — mentre il database riceve `POINT(lng lat)` con SRID 0,
 * perché la conversione la fa `Point::getWktData()`. Verificato in `docs/SCHEMA.md` §3.2.
 */
class Venue extends Model implements HasMedia
{
    use HasEditorialContent;

    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    use HasImageVariants;
    use HasSafeEditorContent;
    use HasSlug;
    use HasSpatial;
    use InteractsWithMedia;
    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'name',
        'slug',
        'type',
        'description',
        'short_description',
        'address',
        'address_extra',
        'postal_code',
        'municipality',
        'zone',
        'province_code',
        'lat',
        'lng',
        'location',
        'phone',
        'email',
        'website',
        'socials',
        'opening_hours',
        'transit',
        'capacity',
        'accessibility',
        'info',
        'requires_membership',
        'membership_notes',
        'status',
        'is_verified',
        'is_nonprofit',
        'plan',
        'auto_publish',
        'ticketing_enabled',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'claim_token',
        'default_event_settings',
        'stats_cache',
    ];

    /**
     * Le colonne su cui `/cerca` interroga. Il comune entra fra le colonne
     * cercabili perché è così che si cerca un locale quando non se ne ricorda
     * il nome: "circolo Este".
     *
     * @return array<string, string|null>
     */
    #[SearchUsingFullText(['description'])]
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'short_description' => $this->short_description,
            'municipality' => $this->municipality,
            'zone' => $this->zone,
            'address' => $this->address,
            'description' => $this->description,
        ];
    }

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('venue')
            ->logOnly(['status', 'is_verified', 'plan', 'auto_publish', 'ticketing_enabled', 'approved_at', 'approved_by', 'rejection_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery');
    }

    /**
     * Le sei conversioni di §12.1 — `thumb`, `card`, `full`, ognuna in WebP e
     * in AVIF — valgono per tutte le raccolte di questo modello: sono
     * immagini, e un'immagine si serve nello stesso modo ovunque compaia.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->registerImageVariants();
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'venue_user')
            ->withPivot(['role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('role', VenueRole::Owner->value);
    }

    /** @return BelongsToMany<User, $this> */
    public function editors(): BelongsToMany
    {
        return $this->members()->wherePivot('role', VenueRole::Editor->value);
    }

    /** @return HasMany<VenueApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(VenueApplication::class);
    }

    /** @return HasMany<EventSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class);
    }

    /** @return HasMany<ImportSource, $this> */
    public function importSources(): HasMany
    {
        return $this->hasMany(ImportSource::class);
    }

    /** @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** @return MorphMany<Follow, $this> */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', VenueStatus::Approved);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeOfStatus(Builder $query, VenueStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeOfType(Builder $query, VenueType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('is_verified', true);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeInCity(Builder $query, City|int $city): void
    {
        $query->where('city_id', $city instanceof City ? $city->getKey() : $city);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public function scopeInMunicipality(Builder $query, string $municipality): void
    {
        $query->where('municipality', $municipality);
    }

    /**
     * Il quartiere del locale — «Portello», «Arcella» — che è la scala a cui
     * si cerca dentro una città. Il comune, in un capoluogo, è lo stesso per
     * tutti e non separa niente.
     *
     * @param  Builder<Venue>  $query
     */
    public function scopeInZone(Builder $query, string $zone): void
    {
        $query->where('zone', $zone);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VenueType::class,
            'status' => VenueStatus::class,
            'plan' => VenuePlan::class,
            'location' => Point::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'socials' => 'array',
            'opening_hours' => 'array',
            'transit' => AsTransitGuide::class,
            'accessibility' => AsAccessibilityProfile::class,
            'info' => AsFacts::class,
            'default_event_settings' => 'array',
            'stats_cache' => 'array',
            'capacity' => 'integer',
            'requires_membership' => 'boolean',
            'is_verified' => 'boolean',
            'is_nonprofit' => 'boolean',
            'auto_publish' => 'boolean',
            'ticketing_enabled' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }
}
