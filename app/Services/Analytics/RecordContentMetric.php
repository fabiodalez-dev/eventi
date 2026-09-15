<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordContentMetric
{
    public function record(string $type, int $id, ContentMetric $metric, ?int $occurrenceId = null): void
    {
        $subject = match ($type) {
            'event' => Event::query()->whereIn('status', [EventStatus::Published, EventStatus::Archived])->findOrFail($id),
            'venue' => Venue::query()->where('status', VenueStatus::Approved)->findOrFail($id),
            'organizer' => Organizer::query()->where('is_active', true)->findOrFail($id),
            default => abort(404),
        };
        $date = CarbonImmutable::now($subject->city->timezone)->toDateString();
        if ($occurrenceId !== null) {
            abort_unless($subject instanceof Event && $subject->occurrences()->whereKey($occurrenceId)->exists(), 404);
        }
        $table = $type === 'event' ? 'event_views_daily' : 'profile_views_daily';
        $key = $type === 'event' ? ['event_id' => $id, 'date' => $date]
            : ['profile_type' => $type, 'profile_id' => $id, 'date' => $date];
        $column = $metric->value;
        // Only aggregate counters: no IP, browser identifier or visitor record.
        DB::transaction(function () use ($table, $key, $column, $occurrenceId, $date): void {
            DB::table($table)->upsert([
                [...$key, $column => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
            ], array_keys($key), [$column => DB::raw('`'.$column.'` + 1'), 'updated_at']);
            if ($occurrenceId !== null) {
                DB::table('occurrence_views_daily')->upsert([
                    ['occurrence_id' => $occurrenceId, 'date' => $date, $column => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
                ], ['occurrence_id', 'date'], [$column => DB::raw('`'.$column.'` + 1'), 'updated_at']);
            }
        });
    }
}
