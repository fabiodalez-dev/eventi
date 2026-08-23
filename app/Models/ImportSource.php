<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportSourceType;
use Database\Factories\ImportSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSource extends Model
{
    /** @use HasFactory<ImportSourceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'venue_id',
        'type',
        'url',
        'credentials',
        'mapping',
        'default_category_id',
        'is_active',
        'last_run_at',
        'last_status',
        'last_error',
    ];

    /** @var list<string> */
    protected $hidden = [
        'credentials',
    ];

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
    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    /**
     * @param  Builder<ImportSource>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<ImportSource>  $query
     */
    public function scopeOfType(Builder $query, ImportSourceType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ImportSourceType::class,
            'credentials' => 'encrypted',
            'mapping' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
