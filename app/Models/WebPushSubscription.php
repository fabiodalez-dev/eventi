<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription;

/**
 * L'iscrizione di un browser al canale push, letta da `devices` (§15.8).
 *
 * **Non e' una seconda tabella.** Il pacchetto ne porta una propria,
 * `push_subscriptions`, e per usarla basterebbe pubblicarne la migrazione: ma
 * l'iscrizione di un browser e' gia' un dispositivo, e i dispositivi esistono
 * qui da prima che il canale ci fosse — `POST /v1/me/devices` scrive
 * `endpoint` e `keys` da §15.8, `last_seen_at` e' cio' su cui §15.6 decide il
 * canale, `revoked_at` e' cio' che fa ripiegare sull'email. Due archivi dello
 * stesso fatto vorrebbero dire, a ogni invio, scegliere quale dei due crede.
 *
 * Questa classe e' quindi una **lettura** di `devices` nella forma che il
 * canale si aspetta: `endpoint`, `public_key`, `auth_token`,
 * `content_encoding`. Le ultime tre non sono colonne — le prime due stanno
 * dentro `keys`, la terza e' una costante del protocollo.
 *
 * Non scrive: `$fillable` e' vuoto di proposito. Chi crea e aggiorna righe in
 * `devices` e' `DeviceController` per l'API e `PushSubscriptionController` per
 * il browser; qui passerebbe da colonne che non esistono.
 *
 * @property-read string $endpoint
 * @property-read array<string, string>|null $keys
 * @property-read CarbonImmutable|null $last_seen_at
 * @property-read CarbonImmutable|null $revoked_at
 */
final class WebPushSubscription extends PushSubscription
{
    protected $table = 'devices';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, string> */
    protected $casts = [
        'keys' => 'array',
        'last_seen_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /**
     * Le righe di `devices` che sono davvero iscrizioni push di un browser.
     *
     * Uno scope globale e non un filtro ripetuto a ogni chiamata: questo
     * modello e' quello che il canale ottiene senza passare da noi — il
     * pacchetto lo costruisce da `config('webpush.model')` — e una riga di
     * `devices` senza `endpoint` mandata al servizio push sarebbe un errore
     * emesso al momento della consegna, cioe' nel punto piu' lontano da qui.
     */
    protected static function booted(): void
    {
        self::addGlobalScope('push', static function (Builder $query): void {
            $query->where('platform', DevicePlatform::Web->value)
                ->whereNotNull('endpoint')
                ->whereNull('revoked_at');
        });
    }

    /**
     * I dispositivi che §15.6 considera vivi: «push su device attivo negli
     * ultimi 30 giorni». La soglia e' in `config/notifications.php` perche' e'
     * una regola di prodotto, non una costante di protocollo.
     *
     * @param  Builder<WebPushSubscription>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $days = config()->integer('notifications.push.device_active_days');

        $query->where('last_seen_at', '>=', CarbonImmutable::now()->subDays($days));
    }

    /**
     * Un'iscrizione non si cancella, si **revoca**.
     *
     * Il gestore dei referti del pacchetto chiama `delete()` quando il
     * servizio push risponde che l'iscrizione e' scaduta: senza questo
     * override quella chiamata cancellerebbe la riga di `devices`, e con essa
     * la traccia che quel browser c'era. §15.6 vuole invece `revoked_at`
     * valorizzato, che e' anche cio' che fa `DeviceController::destroy`.
     */
    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    }

    /*
     * I tre valori che il canale legge e che in `devices` non sono colonne.
     *
     * Sono scritti nella forma classica `get<Nome>Attribute` e non con la
     * classe `Attribute`: quella e' generica e invariante, e su un valore che
     * puo' mancare — cioe' su tutti e due i primi — l'analisi statica non
     * riesce a riconoscere per uguale il tipo che dichiariamo e quello che
     * inferisce. Fra riscrivere il tipo per compiacerla e usare l'altra forma,
     * che Eloquent supporta per intero, la seconda non costa niente.
     */

    /**
     * La chiave pubblica del browser sta dentro `keys` come `p256dh`: e' il
     * nome che l'API `PushSubscription` del browser le da', ed e' quello che
     * il client ci consegna.
     */
    public function getPublicKeyAttribute(): ?string
    {
        return $this->keyPart('p256dh');
    }

    public function getAuthTokenAttribute(): ?string
    {
        return $this->keyPart('auth');
    }

    /**
     * Dichiarata invece che lasciata a `null`, che il canale interpreterebbe
     * comunque cosi': `aes128gcm` e' l'unica codifica che i browser in circolo
     * accettano da anni, e leggerla scritta risparmia a chi legge la domanda
     * su dove finisca una colonna che in `devices` non c'e'.
     */
    public function getContentEncodingAttribute(): ContentEncoding
    {
        return ContentEncoding::aes128gcm;
    }

    private function keyPart(string $name): ?string
    {
        $keys = $this->keys;

        return is_array($keys) && is_string($keys[$name] ?? null) ? $keys[$name] : null;
    }
}
