<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il numero casuale di un gettone di visualizzazione già contato.
 *
 * È la deduplica: la chiave primaria è il numero stesso, e chi conta lo
 * inserisce con `INSERT IGNORE` (`insertOrIgnore`). Se la riga entra la
 * visualizzazione è nuova, altrimenti è un doppione. Il clic aggiorna
 * `clicked_at` sulla stessa riga, così conta una volta sola.
 *
 * Righe di servizio, potate dopo 6 ore: non sono uno storico e non dicono
 * niente di chi guardava.
 *
 * @property string $nonce
 * @property int $sponsorship_id
 * @property string $placement
 * @property CarbonImmutable $seen_at
 * @property CarbonImmutable|null $clicked_at
 */
class SponsorshipImpressionNonce extends Model
{
    protected $table = 'sponsorship_impression_nonces';

    protected $primaryKey = 'nonce';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'nonce',
        'sponsorship_id',
        'placement',
        'seen_at',
        'clicked_at',
    ];

    /** @return BelongsTo<Sponsorship, $this> */
    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seen_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
        ];
    }
}
