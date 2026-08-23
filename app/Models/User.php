<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\VenueRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'locale',
        'notification_preferences',
        'daily_digest_time',
        'quiet_hours',
        'marketing_opt_in_at',
        'last_active_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return BelongsToMany<Venue, $this> */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'venue_user')
            ->withPivot(['role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Venue, $this> */
    public function ownedVenues(): BelongsToMany
    {
        return $this->venues()->wherePivot('role', VenueRole::Owner->value);
    }

    /** @return HasMany<Venue, $this> */
    public function approvedVenues(): HasMany
    {
        return $this->hasMany(Venue::class, 'approved_by');
    }

    /** @return HasMany<VenueApplication, $this> */
    public function venueApplications(): HasMany
    {
        return $this->hasMany(VenueApplication::class);
    }

    /** @return HasMany<VenueApplication, $this> */
    public function reviewedVenueApplications(): HasMany
    {
        return $this->hasMany(VenueApplication::class, 'reviewed_by');
    }

    /** @return HasMany<Event, $this> */
    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'created_by');
    }

    /** @return HasMany<SavedEvent, $this> */
    public function savedEvents(): HasMany
    {
        return $this->hasMany(SavedEvent::class);
    }

    /** @return BelongsToMany<EventOccurrence, $this> */
    public function savedOccurrences(): BelongsToMany
    {
        return $this->belongsToMany(EventOccurrence::class, 'saved_events', 'user_id', 'occurrence_id')
            ->withPivot(['reminder_sent_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Follow, $this> */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<ScheduledNotification, $this> */
    public function scheduledNotifications(): HasMany
    {
        return $this->hasMany(ScheduledNotification::class);
    }

    /** @return HasMany<NotificationLog, $this> */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_user_id');
    }

    /** @return HasMany<Report, $this> */
    public function reviewedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reviewed_by');
    }

    /** @return HasMany<EventSubmission, $this> */
    public function reviewedSubmissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class, 'reviewed_by');
    }

    /**
     * Chi entra in un pannello Filament (§9 e §10 del piano).
     *
     * `/admin` è la redazione: amministratori, amministratori di sistema e
     * moderatori. `/gestione` è il pannello dei locali e guarda invece
     * l'appartenenza alla pivot `venue_user`, perché lì contare i ruoli
     * globali non basterebbe a dire *quale* locale si gestisce.
     *
     * Il permesso di fare qualcosa una volta dentro non si decide qui: quello
     * resta alle Policy di `app/Policies`, che Filament interroga da sé.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isEditorialStaff(),
            'venue' => $this->venues()->exists(),
            default => false,
        };
    }

    /**
     * I locali fra cui si può passare con lo switcher di `/gestione` (§10).
     *
     * Sono esattamente quelli in cui la persona ha una riga in `venue_user`:
     * lo stesso insieme che interrogano le Policy. Se un giorno divergessero,
     * lo switcher offrirebbe un locale che poi nessuna azione lascia toccare.
     *
     * @return Collection<int, Venue>
     */
    public function getTenants(Panel $panel): Collection
    {
        /** @var Collection<int, Venue> $venues */
        $venues = $this->venues()->orderBy('name')->get();

        return $venues;
    }

    /**
     * La barriera contro l'ID scritto a mano nell'indirizzo (§18 scenario F):
     * `IdentifyTenant` chiama questo metodo prima di qualunque altra cosa e
     * risponde 404 se dice di no. Le Policy restano comunque il secondo
     * controllo su ogni riga — questo è il primo, non l'unico.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Venue
            && $this->venues()->whereKey($tenant->getKey())->exists();
    }

    public function isEditorialStaff(): bool
    {
        return $this->hasAnyRole([
            UserRole::Admin->value,
            UserRole::SuperAdmin->value,
            UserRole::Moderator->value,
        ]);
    }

    /**
     * §15.2 del piano: la verifica dell'email è obbligatoria **prima di
     * qualunque invio**. Un account non verificato può salvare eventi e
     * seguire locali, ma nessuna notifica può partire verso di lui.
     *
     * Il controllo vive qui e non in `routeNotificationForMail()` perché
     * proprio l'email di verifica viaggia su quel canale: azzerare
     * l'indirizzo finché l'account non è verificato renderebbe la verifica
     * stessa irraggiungibile.
     */
    public function canReceiveNotifications(): bool
    {
        return $this->hasVerifiedEmail();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'quiet_hours' => 'array',
            'marketing_opt_in_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }
}
