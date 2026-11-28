<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialPublicationStatus;
use App\Jobs\Social\PublishSocial;
use App\Models\City;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Support\SecurityLog;
use App\Support\Social\SocialErrorMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * La pubblicazione sui social dei pacchetti della redazione (R-SO-03,
 * R-SO-04): pubblica adesso, programma, riprogramma, annulla una
 * programmazione, riprova, e lo storico della città.
 *
 * Stava dentro la pagina «Social» del pannello
 * (`InteractsWithSocialStudio`); adesso la chiamano la pagina e l'API della
 * redazione nell'app, con le stesse regole. Chi può farlo (admin e super
 * admin, solo nello studio della redazione) lo controlla chi chiama.
 *
 * Gli errori delle regole di qui sono `ValidationException` con chiavi
 * neutre (`scheduled_at`, `publish`): la pagina le rimette sotto i suoi
 * campi, l'API le restituisce come 422. Quelli di `SocialPublisher::enqueue`
 * (collegamento non verificato, storia, didascalia troppo lunga, evento
 * cambiato) restano `RuntimeException`, come prima.
 */
final class EditorialPublishing
{
    /** Quante pubblicazioni mostra lo storico della pagina. */
    public const HISTORY = 30;

    public function __construct(private readonly SocialPublisher $publisher) {}

    /**
     * Le pubblicazioni della città, dalla più recente: anche quelle il cui
     * pacchetto è stato tolto dalla pulizia, riconosciute dal collegamento
     * con cui sono uscite.
     *
     * @return Builder<SocialPublication>
     */
    public function history(City $city): Builder
    {
        $id = $city->getKey();

        return SocialPublication::query()
            ->where(fn ($query) => $query->whereIn('social_batch_id', SocialBatch::where('city_id', $id)->select('id'))
                ->orWhere(fn ($orphan) => $orphan->whereNull('social_batch_id')->whereIn('social_connection_id', SocialConnection::where('city_id', $id)->select('id'))))
            ->latest();
    }

    /**
     * La data e ora scritte a mano (`Y-m-d\TH:i`, nel fuso della città),
     * in UTC. Deve essere nel futuro.
     */
    public function when(string $local, string $timezone): CarbonImmutable
    {
        $when = CarbonImmutable::parse($local, $timezone)->utc();
        if ($when->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_at' => __('social.schedule.future')]);
        }

        return $when;
    }

    /**
     * Programma un pacchetto. Un pacchetto già nello storico non si
     * programma di nuovo: si cambia la programmazione da lì.
     */
    public function schedule(SocialBatch $batch, CarbonImmutable $when): void
    {
        if (SocialPublication::where('social_batch_id', $batch->getKey())->exists()) {
            throw ValidationException::withMessages(['scheduled_at' => __('social.schedule.already')]);
        }
        $this->publisher->enqueue($batch, scheduledAt: $when);
    }

    /** Pubblica adesso, sui canali attivi e verificati. */
    public function publish(SocialBatch $batch): void
    {
        $this->publisher->enqueue($batch);
    }

    /** Annulla una programmazione ancora in attesa; vero se qualcosa è cambiato. */
    public function cancel(int $id): bool
    {
        $changed = SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['status' => SocialPublicationStatus::Cancelled->value]) > 0;

        if ($changed) {
            SecurityLog::configurazione('post_social_annullato', [
                'oggetto' => 'social_publication', 'id' => $id,
                'prima' => ['status' => SocialPublicationStatus::Scheduled->value], 'dopo' => ['status' => SocialPublicationStatus::Cancelled->value],
            ]);
        }

        return $changed;
    }

    /**
     * Sposta una programmazione ancora in attesa. Il fuso è quello della
     * città del collegamento, che resta anche se il pacchetto è stato tolto.
     * Vero se qualcosa è cambiato.
     */
    public function reschedule(int $id, string $local): bool
    {
        $publication = SocialPublication::findOrFail($id);
        $city = City::findOrFail(SocialConnection::findOrFail($publication->social_connection_id)->city_id);
        $when = $this->when($local, $city->timezone);

        $changed = SocialPublication::whereKey($id)->where('status', SocialPublicationStatus::Scheduled)
            ->update(['scheduled_at' => $when]) > 0;

        if ($changed) {
            SecurityLog::configurazione('post_social_riprogrammato', [
                'oggetto' => 'social_publication', 'id' => $id,
                'prima' => ['scheduled_at' => $publication->scheduled_at?->utc()->toIso8601String()],
                'dopo' => ['scheduled_at' => $when->utc()->toIso8601String()],
            ]);
        }

        return $changed;
    }

    /**
     * La pubblicazione da riprovare: solo una non riuscita o incerta (422
     * altrimenti), e solo se il pacchetto c'è ancora (le immagini servono).
     */
    public function retryable(int $id): SocialPublication
    {
        $publication = SocialPublication::findOrFail($id);
        abort_unless(in_array($publication->status, [SocialPublicationStatus::Failed, SocialPublicationStatus::Uncertain], true), 422);
        if ($publication->batch === null) {
            throw ValidationException::withMessages(['publish' => __('social.studio.batch_gone')]);
        }

        return $publication;
    }

    /**
     * Rimette in coda una pubblicazione di `retryable()`, dopo il controllo a
     * mano sul social: gli eventi devono essere ancora quelli del pacchetto.
     * Vero se è stata rimessa in coda (un altro può averla già ripresa).
     */
    public function retry(SocialPublication $publication): bool
    {
        $batch = $publication->batch;
        if ($batch === null) {
            throw ValidationException::withMessages(['publish' => __('social.studio.batch_gone')]);
        }
        $this->publisher->assertFresh($batch);
        $connection = SocialConnection::findOrFail($publication->social_connection_id);
        $id = (int) $publication->getKey();
        $changed = SocialPublication::whereKey($id)->where('status', $publication->status->value)->update(['status' => SocialPublicationStatus::Queued->value, 'error' => null, 'remote_ids' => json_encode(['page_id' => $connection->page_id, 'instagram_id' => $connection->instagram_id, 'telegram_chat_id' => $connection->telegram_chat_id], JSON_THROW_ON_ERROR)]);
        if ($changed) {
            PublishSocial::dispatch($id);
        }

        return $changed > 0;
    }

    /** L'etichetta di uno stato, come nello storico della pagina. */
    public static function statusLabel(SocialPublicationStatus $status): string
    {
        return (string) __('social.'.$status->value.'_status');
    }

    /**
     * Una riga dello storico per l'app: quando, dove, quale parte, stato,
     * errore, se il pacchetto c'è ancora, e le azioni della riga come i
     * pulsanti della pagina.
     *
     * @return array<string, mixed>
     */
    public static function row(SocialPublication $publication, string $timezone): array
    {
        $lost = $publication->hasLostBatch();
        $status = $publication->status;

        return [
            'id' => (int) $publication->getKey(),
            'created_at' => $publication->created_at?->copy()->utc()->toIso8601String(),
            'platform' => (string) $publication->platform,
            'platform_label' => ucfirst((string) $publication->platform),
            'part' => (int) $publication->part + 1,
            'status' => ['value' => $status->value, 'label' => self::statusLabel($status)],
            'scheduled_at' => $publication->scheduled_at?->utc()->toIso8601String(),
            'timezone' => $timezone,
            /* Mai il testo di un errore del database, anche se una versione vecchia l'ha salvato. */
            'error' => SocialErrorMessage::stored($publication->error !== null ? (string) $publication->error : null),
            'batch_id' => $lost ? null : (string) $publication->social_batch_id,
            'batch_gone' => $lost,
            'abilities' => [
                'reschedule' => $status === SocialPublicationStatus::Scheduled,
                'cancel' => $status === SocialPublicationStatus::Scheduled,
                'retry' => ! $lost && in_array($status, [SocialPublicationStatus::Failed, SocialPublicationStatus::Uncertain], true),
            ],
        ];
    }
}
