<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $created_at
 *
 * Un commento alla scheda di un evento.
 *
 * ## Perché qui non c'è `ContentVersion::bump()`
 *
 * `CatalogReview::booted()` lo chiama a ogni salvataggio, e lì è giusto: le
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
            'moderated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Quante reazioni ha il commento.
     *
     * Di norma il valore arriva già contato da `withCount('reactions')` — lo
     * fanno `EventCommentQuery` per le pagine pubbliche e `->counts()` nelle
     * tabelle dei pannelli — e allora questo accessor si limita a convertirlo.
     *
     * Il conto a richiesta non è un ripiego dimenticato: è la via che usa
     * `ToggleReaction`, che legge il numero su un modello preso con
     * `lockForUpdate()` dentro la transazione, subito dopo aver scritto. Lì un
     * conteggio caricato prima sarebbe quello vecchio, ed è proprio il numero
     * che torna al browser per aggiornare il contatore.
     *
     * Attenzione quando si scrive codice nuovo: fuori da quel caso, leggere
     * questo attributo su una collezione non contata fa una query per riga
     * senza dirlo. Il conteggio va chiesto con `withCount`.
     *
     * Non c'è un cast dichiarato per questo attributo, e sarebbe inutile
     * metterlo: un accessor ha la precedenza sui cast, quindi non verrebbe
     * mai applicato.
     */
    public function getReactionsCountAttribute(mixed $value): int
    {
        return $value === null ? $this->reactions()->count() : (int) $value;
    }

    /** @return BelongsTo<self, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function permalink(): string
    {
        return route('events.show', ['slug' => $this->event->slug, 'commento' => $this->id]).'#commento-'.$this->id;
    }

    /** @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->whereHas('user')->where(function (Builder $q) use ($user): void {
            $q->where('status', EventCommentStatus::Published);
            if ($user !== null) {
                $q->orWhere('user_id', $user->id);
            }
        });
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === EventCommentStatus::Published
            && $this->user !== null
            && ($this->parent_id === null || ($this->parent !== null && $this->parent->status === EventCommentStatus::Published && $this->parent->user !== null));
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
