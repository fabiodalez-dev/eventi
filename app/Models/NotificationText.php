<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Un testo di email riscritto dal pannello.
 *
 * @property string $key
 * @property string $value
 */
final class NotificationText extends Model
{
    /** Quanto restano in cache le sovrascritture. */
    private const TTL = 3600;

    private const CACHE_KEY = 'notification-texts';

    protected $fillable = ['key', 'value', 'updated_by'];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Tutte le sovrascritture, come `chiave => testo`.
     *
     * **In cache, e non per capriccio.** Le traduzioni si caricano a ogni
     * richiesta che ne usa una: senza cache questa tabella verrebbe letta
     * anche per disegnare una pagina pubblica che non manda alcuna email.
     *
     * @return array<string, string>
     */
    public static function tutte(): array
    {
        /** @var array<string, string> */
        return Cache::remember(self::CACHE_KEY, self::TTL, static function (): array {
            /* Il `try` non è pigrizia: questo metodo viene chiamato anche
               mentre le migrazioni girano — quando la tabella non esiste
               ancora — e da comandi eseguiti su un database non pronto. Lì la
               risposta giusta è «nessuna sovrascrittura», non un errore che
               impedisce di migrare. */
            try {
                /** @var array<string, string> $righe */
                $righe = self::query()->pluck('value', 'key')->all();

                return $righe;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function dimenticaLaCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        /* Salvare o cancellare un testo deve vedersi subito: chi ha appena
           riscritto un'email va a provarla, e trovare quella vecchia gli fa
           credere di non aver salvato. */
        self::saved(static fn () => self::dimenticaLaCache());
        self::deleted(static fn () => self::dimenticaLaCache());
    }
}
