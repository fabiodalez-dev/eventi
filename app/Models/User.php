<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VenueRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
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
