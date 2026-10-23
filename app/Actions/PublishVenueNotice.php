<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VenueNoticeKind;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueNotice;
use App\Services\Notifications\NotificationScheduler;
use App\Support\ContentVersion;
use App\Support\EditorContent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il locale avvisa chi lo segue: pausa estiva, chiusura, riapertura, un
 * cambio di gestione. L'avviso va sulla scheda pubblica e, una volta sola,
 * a chi segue il locale con le notifiche accese.
 *
 * Uno ogni sette giorni per locale. Non è una penalità: è la differenza fra
 * un avviso e una newsletter, e chi segue un locale ha acceso il primo, non
 * la seconda.
 */
final class PublishVenueNotice
{
    public const MIN_DAYS_BETWEEN = 7;

    public function __construct(private readonly NotificationScheduler $scheduler) {}

    public function handle(Venue $venue, User $author, VenueNoticeKind $kind, string $body, ?CarbonImmutable $until): VenueNotice
    {
        return DB::transaction(function () use ($venue, $author, $kind, $body, $until): VenueNotice {
            $recent = $venue->notices()->where('created_at', '>=', now()->subDays(self::MIN_DAYS_BETWEEN))->exists();
            if ($recent) {
                throw ValidationException::withMessages(['body' => __('manage.notices.too_soon', ['days' => self::MIN_DAYS_BETWEEN])]);
            }

            $notice = $venue->notices()->create([
                'user_id' => $author->getKey(),
                'kind' => $kind,
                'body' => EditorContent::clean('note', $body),
                'until' => $until?->toDateString(),
            ]);

            $this->scheduler->announceVenueNotice($notice);
            activity('venue')->causedBy($author)->performedOn($venue)->withProperties(['notice_id' => $notice->getKey(), 'kind' => $kind->value])->log('notice_published');
            DB::afterCommit(fn () => ContentVersion::bump((int) $venue->city_id));

            return $notice;
        });
    }
}
