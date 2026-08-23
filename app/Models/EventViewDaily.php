<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventViewDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventViewDaily extends Model
{
    /** @use HasFactory<EventViewDailyFactory> */
    use HasFactory;

    protected $table = 'event_views_daily';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'date',
        'views',
        'unique_views',
        'direction_clicks',
        'ticket_clicks',
        'shares',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views' => 'integer',
            'unique_views' => 'integer',
            'direction_clicks' => 'integer',
            'ticket_clicks' => 'integer',
            'shares' => 'integer',
        ];
    }
}
