<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una raccolta di date con un nome, condivisibile con un link.
 */
class EventCollection extends Model
{
    public const MAX_PER_USER = 20;

    public const MAX_ITEMS = 100;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (EventCollection $collection): void {
            $collection->token ??= Str::random(24);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<EventCollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EventCollectionItem::class, 'collection_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsToMany<EventOccurrence, $this> */
    public function occurrences(): BelongsToMany
    {
        return $this->belongsToMany(EventOccurrence::class, 'event_collection_items', 'collection_id', 'occurrence_id')
            ->withPivot(['note', 'position'])->orderByPivot('position');
    }

    public function publicUrl(): string
    {
        return route('collections.show', $this->token);
    }

    /** Il nome con cui si presenta chi ha fatto la raccolta: il profilo pubblico, se c'è. */
    public function ownerLabel(): ?string
    {
        $profile = $this->user?->communityProfile;

        return $profile?->display_name ?: $profile?->handle;
    }
}
