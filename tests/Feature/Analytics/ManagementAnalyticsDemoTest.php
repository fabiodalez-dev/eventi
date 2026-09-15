<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ManagementAnalyticsDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('seeds portable weekly analytics fixtures without duplicating counters or resetting passwords', function (): void {
    $city = testCity();
    testCategory();
    $this->seed(RolesAndPermissionsSeeder::class);
    $week = CarbonImmutable::parse('2026-09-14', $city->timezone);
    $asOf = $week->addDay();
    $seeder = app(ManagementAnalyticsDemoSeeder::class);
    $result = $seeder->seedWeek($city, $week, $asOf);
    $user = User::where('email', ManagementAnalyticsDemoSeeder::EMAIL)->firstOrFail();
    expect(Hash::check($result['password'], $user->password))->toBeTrue();
    expect($user->canAccessTenant($result['venue']))->toBeTrue();
    expect($user->canAccessTenant($result['organizer']))->toBeTrue();
    expect(Event::where('venue_id', $result['venue']->id)->count())->toBe(6);
    expect(Sponsorship::count())->toBe(3);
    expect(DB::table('event_views_daily')->count())->toBe(180);
    expect(DB::table('profile_views_daily')->count())->toBe(60);
    expect(DB::table('event_occurrences')->min('starts_at'))->toBe('2026-09-15 18:30:00');
    expect(DB::table('event_occurrences')->max('starts_at'))->toBe('2026-09-20 18:30:00');
    $clicks = (int) DB::table('sponsorship_clicks')->count();
    expect((int) Sponsorship::sum('clicks'))->toBe($clicks);
    expect((int) DB::table('sponsorship_daily_stats')->sum('clicks'))->toBe($clicks);
    $repeat = $seeder->seedWeek($city, $week, $asOf);
    expect($repeat['password'])->toBeNull();
    expect($user->fresh()->password)->toBe($user->password);
    expect(Sponsorship::count())->toBe(3);
    expect(DB::table('sponsorship_clicks')->count())->toBe($clicks);
    expect(DB::table('event_views_daily')->count())->toBe(180);
    expect(DB::table('event_occurrences')->count())->toBe(6);
});
