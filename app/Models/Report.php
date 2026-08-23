<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reportable_type',
        'reportable_id',
        'reason',
        'note',
        'reporter_email',
        'reporter_user_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_note',
        'ip_address',
    ];

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReportStatus::Pending);
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeOfStatus(Builder $query, ReportStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeOfReason(Builder $query, ReportReason $reason): void
    {
        $query->where('reason', $reason);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
