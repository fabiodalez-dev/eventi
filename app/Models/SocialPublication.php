<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pubblicazione sui social di un pacchetto della redazione, una per
 * piattaforma e parte del carosello.
 *
 * `social_batch_id` può essere nullo: la pulizia notturna dei pacchetti
 * (`SocialBatch::prunable`) cancella i pacchetti vecchi con le loro immagini,
 * ma la pubblicazione resta nello storico (`nullOnDelete`). Senza pacchetto si
 * legge ancora (data, piattaforma, parte, stato, errore), ma non si ritenta
 * né si pubblica: le immagini non ci sono più.
 */
class SocialPublication extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<SocialBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SocialBatch::class, 'social_batch_id');
    }

    /** Vero quando il pacchetto (e con lui le immagini) è stato tolto dalla pulizia. */
    public function hasLostBatch(): bool
    {
        return $this->social_batch_id === null;
    }

    protected function casts(): array
    {
        return ['status' => SocialPublicationStatus::class, 'remote_ids' => 'array', 'scheduled_at' => 'immutable_datetime'];
    }
}
