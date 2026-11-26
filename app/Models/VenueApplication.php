<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Models\Concerns\HasSafeEditorContent;
use Database\Factories\VenueApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $venue_data
 * @property string|null $image_path
 * @property Carbon|null $email_confirmed_at
 */
class VenueApplication extends Model
{
    /** @use HasFactory<VenueApplicationFactory> */
    use HasFactory;

    use HasSafeEditorContent;

    /**
     * Il nome dello scope globale che tiene fuori dalla coda le richieste in
     * attesa della conferma dell'email. Chi deve vederle tutte (il listener
     * che le mette in coda, «Le mie richieste», i limiti per email) lo toglie
     * con `withoutGlobalScope(VenueApplication::AWAITING_EMAIL)`.
     */
    public const AWAITING_EMAIL = 'awaiting_email';

    /** Le chiavi ammesse in `venue_data`: tutto il resto si scarta. */
    public const VENUE_DATA_KEYS = ['postal_code', 'municipality', 'public_email', 'public_phone', 'website', 'instagram', 'facebook', 'capacity', 'description'];

    /**
     * **Una richiesta entra in coda quando chi l'ha scritta ha confermato
     * l'email.** Fino ad allora non la vede la redazione: né l'elenco, né il
     * contatore, né la pagina della singola richiesta. Così una richiesta
     * mandata con l'indirizzo di un altro non arriva mai sul tavolo di chi
     * decide.
     *
     * Chi crea una richiesta senza dire niente (le fabbriche dei test, i
     * percorsi vecchi) la mette in coda subito: solo `CreateVenueApplication`
     * scrive `email_confirmed_at = null` di proposito.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::AWAITING_EMAIL, static function (Builder $query): void {
            $query->whereNotNull($query->qualifyColumn('email_confirmed_at'));
        });

        static::creating(static function (self $application): void {
            if (! array_key_exists('email_confirmed_at', $application->getAttributes())) {
                $application->setAttribute('email_confirmed_at', now());
            }
        });
    }

    public function isAwaitingEmail(): bool
    {
        return $this->email_confirmed_at === null;
    }

    /**
     * Solo le richieste in attesa della conferma dell'email (da usare insieme
     * a `withoutGlobalScope(self::AWAITING_EMAIL)`).
     *
     * @param  Builder<VenueApplication>  $query
     */
    public function scopeAwaitingEmail(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('email_confirmed_at'));
    }

    /** Un dato pubblico del locale scritto nella richiesta, o `null`. */
    public function venueDatum(string $key): mixed
    {
        $data = is_array($this->venue_data) ? $this->venue_data : [];
        $value = $data[$key] ?? null;

        return $value === '' ? null : $value;
    }

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'user_id',
        'venue_id',
        'organizer_id',
        'venue_name',
        'contact_name',
        'contact_role',
        'contact_phone',
        'contact_email',
        'address',
        'type',
        'socials',
        'venue_data',
        'image_path',
        'message',
        'documents',
        'status',
        'email_confirmed_at',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    /**
     * Vero se la richiesta l'ha mandata `$user`: dal suo account, o con la
     * sua email come contatto (una richiesta da ospite a suo nome finisce
     * comunque a lui con l'invito). Nessuno approva una richiesta propria:
     * sarebbe darsi da sé un locale o un'organizzazione.
     */
    public function sentBy(User $user): bool
    {
        if ($this->user_id !== null && (int) $this->user_id === (int) $user->getKey()) {
            return true;
        }

        $contact = mb_strtolower(trim((string) $this->contact_email));

        return $contact !== '' && $contact === mb_strtolower(trim((string) $user->email));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function kind(): ApplicationKind
    {
        return $this->kind ?? ApplicationKind::Venue;
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<VenueApplication>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApplicationStatus::Pending);
    }

    /**
     * @param  Builder<VenueApplication>  $query
     */
    public function scopeOfStatus(Builder $query, ApplicationStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ApplicationKind::class,
            'type' => VenueType::class,
            'status' => ApplicationStatus::class,
            'socials' => 'array',
            'venue_data' => 'array',
            'documents' => 'array',
            'email_confirmed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
