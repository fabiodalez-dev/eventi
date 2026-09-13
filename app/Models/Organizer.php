<?php

namespace App\Models;

use App\Enums\ContactMode;
use App\Models\Concerns\HasSafeEditorContent;
use App\Support\ContentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/** @property ContactMode $contact_mode
 * @property string|null $contact_email
 */
class Organizer extends Model
{
    use HasSafeEditorContent;

    /** @param Builder<Organizer> $query */
    public function scopeVisibleInCity(Builder $query, City $city): void
    {
        $query->where('is_active', true)->where(fn ($match) => $match->where('city_id', $city->id)
            ->orWhereHas('events', fn ($events) => $events->where('city_id', $city->id)->whereIn('status', ['published', 'archived'])));
    }

    use HasSlug;

    protected $attributes = ['contact_mode' => 'disabled'];

    protected $fillable = ['contact_mode', 'contact_email', 'city_id', 'owner_id', 'name', 'slug', 'description', 'website', 'email', 'is_active'];

    protected static function booted(): void
    {
        static::saved(function (Organizer $organizer): void {
            $cities = $organizer->events()->pluck('city_id')->push($organizer->city_id)->unique();
            foreach ($cities as $cityId) {
                ContentVersion::bump((int) $cityId);
            }
        });
    }

    protected function casts(): array
    {
        return ['contact_mode' => ContactMode::class, 'is_active' => 'boolean'];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->doNotGenerateSlugsOnUpdate();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function managedBy(User $user): bool
    {
        return $this->is_active && ((int) $this->owner_id === (int) $user->id || $this->users()->whereKey($user->id)->exists());
    }
}
