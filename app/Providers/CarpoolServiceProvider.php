<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CarpoolProfile;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Report;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\UnifiedNotifications;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class CarpoolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DatabaseNotification::created(fn ($notification) => app(UnifiedNotifications::class)->archived($notification));
        Report::created(fn ($report) => app(CommunitySafety::class)->importReport($report));
        RateLimiter::for('carpool-write', fn (Request $r) => [Limit::perMinute(20)->by('cp:'.$r->user()?->id.':'.(in_array($r->route('action'), ['withdraw', 'cancel', 'revoke-adult'], true) ? 'safety' : 'write')), Limit::perMinute(120)->by('cp-ip:'.$r->ip())]);
        RateLimiter::for('carpool-chat', fn (Request $r) => Limit::perMinute($r->route('action') === 'send' ? 30 : 120)->by('cp-chat:'.$r->user()?->id.':'.$r->route('action')));
        RateLimiter::for('carpool-report', fn (Request $r) => Limit::perHour(20)->by('cp-report:'.$r->user()?->id));
        User::updated(function (User $user): void {
            if ($user->wasChanged(['email_verified_at', 'whatsapp_verified_at', 'whatsapp_phone_hash', 'carpool_suspended_at', 'community_suspended_at', 'deleted_at'])) {
                app(CarpoolLifecycle::class)->reconcileUser($user->id);
            }
        });
        User::deleted(fn (User $user) => app(CarpoolLifecycle::class)->reconcileUser($user->id));
        CarpoolProfile::updated(function (CarpoolProfile $profile): void {
            if ($profile->wasChanged('adult_declared_at') && $profile->adult_declared_at === null) {
                app(CarpoolLifecycle::class)->reconcileUser($profile->user_id);
            }
        });
        UserBlock::created(fn (UserBlock $block) => app(CarpoolLifecycle::class)->reconcileUser($block->user_id));
        EventOccurrence::updated(function (EventOccurrence $date): void {
            if ($date->wasChanged(['starts_at', 'effective_ends_at', 'venue_id', 'status'])) {
                app(CarpoolLifecycle::class)->reconcileDate($date->id);
            }
        });
        EventOccurrence::deleted(fn (EventOccurrence $date) => app(CarpoolLifecycle::class)->reconcileDate($date->id));
        Event::updated(function (Event $event): void {
            if ($event->wasChanged(['status', 'venue_id', 'custom_location'])) {
                foreach ($event->occurrences()->pluck('id') as $id) {
                    app(CarpoolLifecycle::class)->reconcileDate((int) $id);
                }
            }
        });
        Event::deleted(function (Event $event): void {
            foreach ($event->occurrences()->withTrashed()->pluck('id') as $id) {
                app(CarpoolLifecycle::class)->reconcileDate((int) $id);
            }
        });
    }
}
