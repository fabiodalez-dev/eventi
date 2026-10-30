<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProfileVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CommunityProfile extends Model implements HasMedia
{
    use InteractsWithMedia;

    /* `handle_changed_at` resta fuori di proposito: lo scrive solo il servizio
       che cambia il nome, mai un `fill()` con i dati di una richiesta. */
    protected $fillable = ['handle', 'display_name', 'bio', 'city_id', 'visibility', 'indexable'];

    protected function casts(): array
    {
        return ['visibility' => ProfileVisibility::class, 'indexable' => 'boolean', 'featured' => 'boolean', 'handle_changed_at' => 'immutable_datetime'];
    }

    /**
     * Da quando il nome utente si potrà cambiare di nuovo; null se si può già.
     *
     * L'attesa parte dall'ultimo cambio (`community.handle_change_cooldown_days`).
     * Senza un cambio registrato — profili nati prima della regola, o che hanno
     * solo scelto il primo nome — non c'è niente da aspettare.
     */
    public function handleChangeableFrom(): ?CarbonImmutable
    {
        if (! $this->handle_changed_at instanceof CarbonImmutable) {
            return null;
        }

        $da = $this->handle_changed_at->addDays(max(0, (int) config('community.handle_change_cooldown_days', 30)));

        return $da->isFuture() ? $da : null;
    }

    /**
     * Il messaggio dell'attesa, sotto il campo del nome utente; null se si può
     * già cambiare. Data e ora nel fuso dell'account, perché «dal 31 ottobre»
     * detto a chi alle dieci di quel giorno trova ancora la porta chiusa è una
     * promessa sbagliata.
     */
    public function handleCooldownMessage(): ?string
    {
        $da = $this->handleChangeableFrom();

        if (! $da instanceof CarbonImmutable) {
            return null;
        }

        $fuso = $this->user?->timezone ?: 'Europe/Rome';

        return __('community.handle_cooldown', ['date' => $da->setTimezone($fuso)->translatedFormat('j F Y \a\l\l\e H:i')]);
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
