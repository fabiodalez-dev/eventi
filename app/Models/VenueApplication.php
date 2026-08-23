<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use Database\Factories\VenueApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueApplication extends Model
{
    /** @use HasFactory<VenueApplicationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'venue_id',
        'venue_name',
        'contact_name',
        'contact_role',
        'contact_phone',
        'contact_email',
        'address',
        'type',
        'socials',
        'message',
        'documents',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

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
            'type' => VenueType::class,
            'status' => ApplicationStatus::class,
            'socials' => 'array',
            'documents' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
