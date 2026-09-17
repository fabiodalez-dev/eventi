<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un commento alla scheda di un evento.
 *
 * ## Perché qui non c'è `ContentVersion::bump()`
 *
 * `VenueReview::booted()` lo chiama a ogni salvataggio, e lì è giusto: le
 * recensioni cambiano di rado e la scheda del locale sta nella full-page
 * cache.
 *
 * La scheda di un evento no. `CachePage::isCacheable()` la esclude come prima
 * cosa, perché capienza delle prenotazioni e finestre di vendita non possono
 * essere congelate — quindi un commento nuovo si vede alla richiesta
 * successiva senza che nessuno debba invalidare niente.
 *
 * Replicare il `bump()` sarebbe anzi dannoso: azzererebbe la cache di **tutta
 * la città** a ogni commento e a ogni reazione, cioè a beneficio di nessuno.
 *
 * Il presupposto è tacito e fragile — se un giorno quell'uscita anticipata
 * sparisse, i commenti si congelerebbero per mezz'ora senza un segnale. Per
 * questo esiste un test che lo fissa.
 */
class EventComment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => EventCommentStatus::class,
            'revision' => 'integer',
            'reactions_count' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<EventCommentReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(EventCommentReaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * Quelli che il pubblico può leggere.
     *
     * `whereHas('user')` esclude i commenti di chi ha cancellato l'account:
     * il vincolo li porta via in cascata, ma la condizione tiene coerente
     * anche una lettura che dovesse arrivare prima della cancellazione.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventCommentStatus::Published)->whereHas('user');
    }

    /** @param Builder<self> $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
