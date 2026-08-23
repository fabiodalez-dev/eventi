<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineupRole;
use Database\Factories\LineupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lineup extends Model
{
    /** @use HasFactory<LineupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'occurrence_id',
        'name',
        'role',
        'starts_at',
        'url',
        'sort_order',
    ];

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    /**
     * @param  Builder<Lineup>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Lineup>  $query
     */
    public function scopeOfRole(Builder $query, LineupRole $role): void
    {
        $query->where('role', $role);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => LineupRole::class,
            'starts_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
