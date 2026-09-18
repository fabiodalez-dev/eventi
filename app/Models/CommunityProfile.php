<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProfileVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CommunityProfile extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['handle', 'display_name', 'bio', 'city_id', 'visibility', 'indexable'];

    protected function casts(): array
    {
        return ['visibility' => ProfileVisibility::class, 'indexable' => 'boolean', 'featured' => 'boolean'];
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

    /** @return BelongsToMany<Venue, $this> */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'community_profile_venue');
    }

    public function avatarUrl(): ?string
    {
        if (! $this->hasMedia('avatar')) {
            return null;
        }

        return route(request()->is('api/*') ? 'community.api.avatar' : 'community.avatar', ['profile' => $this->id, 'v' => $this->getFirstMedia('avatar')->id]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->useDisk('local')->singleFile()->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }
}
