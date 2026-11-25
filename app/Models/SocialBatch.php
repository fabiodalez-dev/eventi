<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Un pacchetto di grafiche social: le immagini stanno in
 * `storage/app/social/{id}`, una per serata.
 *
 * **La pulizia** (`model:prune`, ogni notte da `routes/console.php`). Lo
 * studio mostra e riapre gli ultimi dodici pacchetti di ciascun pannello
 * (`SocialStudioDesk::recent`: per locale, e per città quelli della
 * redazione); quelli più vecchi non li raggiunge più nessuno, ma restavano
 * sul disco per sempre, su un hosting con 10 GB di quota. Si tengono i
 * dodici più recenti di ogni locale (e della redazione di ogni città), più
 * quelli che una pubblicazione sui social usa ancora:
 *
 * - una pubblicazione programmata, in coda, in corso, fallita o incerta
 *   (le ultime due si ritentano dallo storico, e `PublishSocial` manda a Meta
 *   l'indirizzo firmato delle immagini del pacchetto);
 * - una pubblicazione di oggi o di ieri, così le grafiche appena uscite si
 *   riaprono ancora dallo studio. Il giorno di margine copre la differenza
 *   fra il fuso della città e quello del server.
 *
 * Le pubblicazioni non se ne vanno con il pacchetto: la chiave è
 * `nullOnDelete`, e lo storico della redazione le mostra anche senza
 * pacchetto (senza immagini). Anche `social:daily`, che riconosce il
 * carosello già pubblicato dalla `dedupe_key` delle pubblicazioni, non
 * dipende dal pacchetto.
 *
 * I pacchetti dei locali non hanno mai pubblicazioni (`SocialPublisher`
 * rifiuta quelli con `venue_id`): per loro vale solo il conto dei dodici.
 */
class SocialBatch extends Model
{
    use HasUuids;
    use Prunable;

    /** Quanti pacchetti recenti restano a ogni locale (e alla redazione di ogni città). */
    public const KEEP_PER_OWNER = 12;

    /** Gli stati in cui una pubblicazione ha ancora bisogno delle immagini del pacchetto. */
    public const LIVE_PUBLICATION_STATUSES = [
        SocialPublicationStatus::Scheduled,
        SocialPublicationStatus::Queued,
        SocialPublicationStatus::Processing,
        SocialPublicationStatus::Failed,
        SocialPublicationStatus::Uncertain,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'items' => 'array', 'date' => 'date'];
    }

    /** @return HasMany<SocialPublication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        // La posizione di ogni pacchetto fra quelli del suo pannello, dal più recente (lo stesso ordine di `recent`).
        $ranked = DB::table('social_batches')->select('id')
            ->selectRaw('row_number() over (partition by city_id, venue_id order by created_at desc, id desc) as position');
        $statuses = array_map(fn (SocialPublicationStatus $status): string => $status->value, self::LIVE_PUBLICATION_STATUSES);

        return static::query()
            ->whereIn('id', DB::query()->fromSub($ranked, 'ranked')->where('position', '>', self::KEEP_PER_OWNER)->select('id'))
            ->whereDoesntHave('publications', fn (Builder $query) => $query->whereIn('status', $statuses))
            ->where(fn (Builder $query) => $query->whereDoesntHave('publications')
                ->orWhereDate('date', '<', now()->subDay()->toDateString()));
    }

    /** Le immagini se ne vanno con il pacchetto. */
    protected function pruning(): void
    {
        Storage::disk('local')->deleteDirectory('social/'.$this->getKey());
    }
}
