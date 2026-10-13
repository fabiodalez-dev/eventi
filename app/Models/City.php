<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEditorialContent;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class City extends Model
{
    /**
     * Identificativo e fuso di ogni città, letti una volta per richiesta.
     *
     * Le interrogazioni che ragionano «a mezzanotte di ciascuna città» ne
     * hanno bisogno tutte, e la stessa pagina arrivava a chiederlo tredici
     * volte. L'elenco cambia solo quando si aggiunge una città.
     *
     * @return Collection<int, static>
     */
    public static function timezones(): Collection
    {
        return once(fn (): Collection => static::query()->get(['id', 'timezone']));
    }

    use HasEditorialContent;

    /** @use HasFactory<CityFactory> */
    use HasFactory;

    use HasSlug;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'province_code',
        'province_name',
        'region',
        'country_code',
        'timezone',
        'center_lat',
        'center_lng',
        'default_zoom',
        'bounds',
        'radius_km',
        'locale',
        'is_active',
        'launched_at',
        'night_cutoff_time',
        'starting_soon_minutes',
        'settings',
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

    /** @return HasMany<Venue, $this> */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
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

    /**
     * @param  Builder<City>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
            'default_zoom' => 'integer',
            'bounds' => 'array',
            'radius_km' => 'integer',
            'is_active' => 'boolean',
            'launched_at' => 'datetime',
            'starting_soon_minutes' => 'integer',
            'settings' => 'array',
        ];
    }
}
