<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('seeds only upcoming dates in five days idempotently without notifications', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-14 12:00');
    $category = testCategory();
    $today = occurrenceAtLocal($city, $category, '2026-09-14 21:00');
    $last = occurrenceAtLocal($city, $category, '2026-09-18 21:00');
    $outside = occurrenceAtLocal($city, $category, '2026-09-19 21:00');
    $past = occurrenceAtLocal($city, $category, '2026-09-13 21:00');
    $notifications = DB::table('scheduled_notifications')->count();
    $this->artisan('events:saved-demo', ['city' => $city->slug, '--dry-run' => true])->assertSuccessful();
    expect(User::where('email', 'like', '%@demo.incitta.invalid')->count())->toBe(0);
    $this->artisan('events:saved-demo', ['city' => $city->slug])->assertSuccessful();
    $count = DB::table('saved_events')->count();
    $this->artisan('events:saved-demo', ['city' => $city->slug])->assertSuccessful();
    expect(DB::table('saved_events')->count())->toBe($count)
        ->and(DB::table('saved_events')->where('occurrence_id', $today->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('saved_events')->where('occurrence_id', $last->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('saved_events')->whereIn('occurrence_id', [$outside->id, $past->id])->count())->toBe(0)
        ->and(DB::table('scheduled_notifications')->count())->toBe($notifications);
});
