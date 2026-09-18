<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\CarpoolCaseStatus;
use App\Enums\Permission;
use App\Enums\ReportStatus;
use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Models\CarpoolAudit;
use App\Models\CarpoolCase;
use App\Models\CarpoolCaseMessage;
use App\Models\EventOccurrence;
use App\Models\Report;
use App\Models\RideConversation;
use App\Models\RideMessage as Message;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CommunitySafety
{
    public function __construct(private CarpoolAccess $access, private CarpoolAuditLog $audit) {}

    public function staff(User $user, Permission $permission, bool $sensitive = false): bool
    {
        return $user->isEditorialStaff() && $user->hasPermissionTo($permission->value)
            && (! $sensitive || $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]));
    }

    public function requireStaff(User $user, Permission $permission, bool $sensitive = false): void
    {
        $this->access->notImpersonating();
        abort_unless($this->staff($user, $permission, $sensitive), 403);
    }

    /** @param array<string, mixed> $data */
    public function report(User $user, array $data): CarpoolCase
    {
        $this->access->notImpersonating();
        $id = app(CarpoolCommands::class)->run($user, $data['request_key'], 'report', $data, null, function (User $actor) use ($data): int {
            $offer = isset($data['offer_id']) ? RideOffer::findOrFail($data['offer_id']) : null;
            $ride = isset($data['request_id']) ? RideRequest::findOrFail($data['request_id']) : null;
            if ($ride) {
                abort_unless($this->access->participant($actor, $ride), 404);
                $offer = $ride->offer;
            }
            if ($offer) {
                abort_unless($this->access->canViewOffer($actor, $offer), 404);
            }
            if (isset($data['message_id'])) {
                abort_unless($ride !== null, 422);
                $chat = RideConversation::where('ride_request_id', $ride->id)->firstOrFail();
                abort_unless(Message::where('conversation_id', $chat->conversation_id)->whereKey($data['message_id'])->exists(), 404);
            }
            $review = isset($data['review_id']) ? RideReview::findOrFail($data['review_id']) : null;
            if ($review) {
                abort_unless(app(RideReviews::class)->reader($actor, $review->driver) && app(RideReviews::class)->published($review->driver)->whereKey($review->id)->exists(), 404);
                abort_if($offer || $ride || isset($data['message_id']), 422);
            }
            $case = CarpoolCase::create(['social_type' => $review ? 'ride_review' : null, 'social_id' => $review?->id,
                'review_snapshot' => $review ? app(RideReviews::class)->resource($review) : null, 'reporter_id' => $actor->id, 'ride_offer_id' => $offer?->id, 'ride_request_id' => $ride?->id,
                'message_id' => $data['message_id'] ?? null, 'reason' => $data['reason'], 'body' => $data['body'], 'status' => CarpoolCaseStatus::Open]);
            $this->audit->record($actor, 'case_opened', $case, ['reason' => $case->reason]);

            return $case->id;
        });

        return CarpoolCase::findOrFail($id);
    }

    public function involved(User $user, CarpoolCase $case): bool
    {
        return $case->reporter_id === $user->id || CarpoolCaseMessage::where('carpool_case_id', $case->id)->where('recipient_id', $user->id)->exists();
    }

    /** @return list<array<string, mixed>> */
    public function correspondence(User $user, CarpoolCase $case, ?int $before = null): array
    {
        abort_unless($this->involved($user, $case), 404);

        return $case->messages()->where('internal', false)->where(fn ($q) => $q->where('author_id', $user->id)->orWhere('recipient_id', $user->id))
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(50)->get()->sortBy('id')->values()->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'mine' => $m->author_id === $user->id, 'created_at' => $m->created_at->toIso8601String()])->all();
    }

    public function reply(User $user, CarpoolCase $case, string $body, string $key): int
    {
        abort_unless($this->involved($user, $case), 404);

        return app(CarpoolCommands::class)->run($user, $key, 'case_reply', ['case' => $case->id, 'body' => $body], null, function (User $actor) use ($case, $body): int {
            $case = CarpoolCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $message = $case->messages()->create(['author_id' => $actor->id, 'body' => $body, 'internal' => false]);
            $case->update(['status' => CarpoolCaseStatus::Open, 'closed_at' => null, 'revision' => $case->revision + 1]);
            $this->audit->record($actor, 'case_reply', $case);

            return $message->id;
        });
    }

    /** @param array<string, mixed> $data */
    public function manage(User $admin, CarpoolCase $case, string $action, string $reason, array $data = []): void
    {
        $this->requireStaff($admin, in_array($action, ['hold', 'release'], true) ? Permission::ManageCommunityRetention : Permission::ManageCommunityCases, in_array($action, ['hold', 'release'], true));
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422, __('carpool.admin.reason'));
        DB::transaction(function () use ($admin, $case, $action, $reason, $data): void {
            if (in_array($action, ['hold', 'release'], true)) {
                DB::table('carpool_mutexes')->where('name', 'retention')->lockForUpdate()->firstOrFail();
            }
            $case = CarpoolCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->revision === (int) ($data['revision'] ?? 0), 409, __('carpool.errors.changed'));
            $changes = ['revision' => $case->revision + 1];
            if ($action === 'assign') {
                $changes += ['assignee_id' => $admin->id, 'status' => CarpoolCaseStatus::Assigned];
            } elseif ($action === 'resolve') {
                $changes += ['status' => CarpoolCaseStatus::Resolved, 'closed_at' => now()];
            } elseif ($action === 'reopen') {
                $changes += ['status' => CarpoolCaseStatus::Open, 'closed_at' => null];
            } elseif ($action === 'hold') {
                $changes += ['hold_until' => now()->addDays(90), 'hold_reason' => mb_substr($reason, 0, 500)];
            } elseif ($action === 'release') {
                $changes += ['hold_until' => null, 'hold_reason' => null];
            } elseif ($action === 'reply') {
                $recipient = (int) ($data['recipient_id'] ?? 0);
                $allowed = array_filter([$case->reporter_id, $case->offer?->driver_id, $case->rideRequest?->user_id]);
                abort_unless(in_array($recipient, $allowed, true), 422);
                $message = $case->messages()->create(['author_id' => $admin->id, 'recipient_id' => $recipient, 'body' => $reason, 'internal' => false]);
                $changes['status'] = CarpoolCaseStatus::Waiting;
                $user = User::find($recipient);
                if ($user) {
                    app(CommunityNotices::class)->send($user, 'carpool', 'support', 'case', $case->id, 'support:'.$message->id);
                }
            } elseif ($action !== 'note') {
                abort(422);
            }
            if ($action !== 'reply') {
                $case->messages()->create(['author_id' => $admin->id, 'body' => $reason, 'internal' => true]);
            }
            $case->update($changes);
            if ($case->source_report_id && in_array($action, ['assign', 'resolve', 'reopen'], true)) {
                Report::whereKey($case->source_report_id)->update(['status' => match ($action) {
                    'resolve' => ReportStatus::Resolved->value, 'assign' => ReportStatus::Reviewing->value, default => ReportStatus::Pending->value,
                }, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'resolution_note' => $reason]);
            }
            $this->audit->record($admin, 'case_'.$action, $case);
        }, 5);
    }

    /** @return array<string, mixed> */
    public function inspect(User $admin, CarpoolCase $case, string $reason, bool $export = false): array
    {
        $this->requireStaff($admin, Permission::ReadCommunityMessages, true);
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422, __('carpool.admin.reason'));
        if ($export) {
            $this->requireStaff($admin, Permission::ExportCommunityEvidence, true);
        }
        $chat = $case->ride_request_id ? RideConversation::where('ride_request_id', $case->ride_request_id)->first() : null;
        $messages = $chat && ! $chat->purged_at && (app(CarpoolRetention::class)->readable($chat) || $case->hold_until?->isFuture()) ? Message::where('conversation_id', $chat->conversation_id)->orderBy('id')->get()->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'at' => $m->created_at->toIso8601String()])->all() : [];
        $this->audit->record($admin, $export ? 'evidence_export' : 'evidence_view', $case, ['reason' => $reason]);
        $result = ['review' => $case->review_snapshot, 'case_id' => $case->id, 'request_id' => $case->ride_request_id, 'messages' => $messages,
            'generated_at' => now()->toIso8601String(), 'operator_id' => $admin->id];
        if ($this->staff($admin, Permission::ReadCommunitySecurity, true)) {
            $result['audit'] = CarpoolAudit::where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'RideOffer')->where('subject_id', $case->ride_offer_id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'RideRequest')->where('subject_id', $case->ride_request_id))
                ->when($chat, fn ($q) => $q->orWhere(fn ($q) => $q->where('subject_type', 'RideConversation')->where('subject_id', $chat->id))))
                ->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'actor_id' => $a->actor_id, 'at' => $a->created_at->toIso8601String(), 'context' => $a->context_expires_at->isFuture() || $case->hold_until?->isFuture() ? $a->context : null, 'metadata' => $a->metadata])->all();
        }
        $result['sha256'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function restrict(User $admin, User $user, string $scope, bool $suspend, string $reason): void
    {
        $this->requireStaff($admin, $scope === 'social' ? Permission::ManageCommunity : Permission::ManageCarpool);
        abort_if(mb_strlen(trim($reason)) < 5, 422, __('carpool.admin.reason'));
        if ($scope === 'global') {
            $this->requireStaff($admin, Permission::ManageUsers);
        }
        $field = match ($scope) {
            'social' => 'social_suspended_at', 'carpool' => 'carpool_suspended_at', 'global' => 'community_suspended_at', default => abort(422)
        };
        DB::transaction(function () use ($admin, $user, $field, $scope, $suspend, $reason): void {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target->forceFill([$field => $suspend ? now() : null])->save();
            $this->audit->record($admin, $suspend ? 'user_restricted' : 'user_restored', $target, ['scope' => $scope, 'reason' => $reason]);
            app(CommunityNotices::class)->send($target, 'carpool', 'moderation', 'profile', $target->id, 'moderation:'.(string) Str::uuid());
        }, 5);
        app(CarpoolLifecycle::class)->reconcileUser($user->id);
    }

    public function importReport(Report $report): void
    {
        if (! in_array($report->reportable_type, ['community_profile', 'community_post', 'community_comment'], true)
            || ! $report->reporter_user_id || ! Schema::hasColumn('carpool_cases', 'source_report_id')) {
            return;
        }
        $case = CarpoolCase::firstOrCreate(['source_report_id' => $report->id], [
            'reporter_id' => $report->reporter_user_id, 'social_type' => $report->reportable_type,
            'social_id' => $report->reportable_id, 'reason' => $report->reason->value,
            'body' => $report->note ?? '', 'status' => in_array($report->status, [ReportStatus::Resolved, ReportStatus::Dismissed], true) ? CarpoolCaseStatus::Resolved : CarpoolCaseStatus::Open,
            'closed_at' => in_array($report->status, [ReportStatus::Resolved, ReportStatus::Dismissed], true) ? ($report->reviewed_at ?? now()) : null,
        ]);
        if ($case->wasRecentlyCreated) {
            $this->audit->record($report->reporter, 'social_case_opened', $case);
        }
    }

    public function cancelRide(User $admin, RideOffer $offer, string $reason): void
    {
        $this->requireStaff($admin, Permission::ManageCarpool);
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422);
        DB::transaction(function () use ($admin, $offer, $reason): void {
            EventOccurrence::withTrashed()->whereKey($offer->occurrence_id)->lockForUpdate()->firstOrFail();
            $offer = RideOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($offer->status, [RideStatus::Draft, RideStatus::Open, RideStatus::Closed], true), 409);
            app(CarpoolService::class)->cancelOffer($offer, $admin, 'moderation');
            $this->audit->record($admin, 'admin_ride_cancelled', $offer, ['reason' => $reason]);
        }, 5);
    }

    public function freezeChat(User $admin, CarpoolCase $case, bool $freeze, string $reason): void
    {
        $this->requireStaff($admin, Permission::ManageCommunityCases);
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422);
        DB::transaction(function () use ($admin, $case, $freeze, $reason): void {
            $case = CarpoolCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->ride_request_id !== null, 422);
            $chat = RideConversation::where('ride_request_id', $case->ride_request_id)->lockForUpdate()->firstOrFail();
            abort_if($chat->purged_at !== null, 409);
            $chat->update(['hidden_at' => $freeze ? now() : null]);
            $case->messages()->create(['author_id' => $admin->id, 'body' => $reason, 'internal' => true]);
            $case->increment('revision');
            $this->audit->record($admin, $freeze ? 'chat_hidden' : 'chat_restored', $chat, ['case_id' => $case->id, 'reason' => $reason]);
        }, 5);
    }

    public function retryDelivery(User $admin, int $id, string $reason): void
    {
        $this->requireStaff($admin, Permission::ManageCarpool);
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422);
        DB::transaction(function () use ($admin, $id, $reason): void {
            $delivery = DB::table('community_delivery_outbox')->where('id', $id)->lockForUpdate()->first();
            abort_unless($delivery && ! $delivery->delivered_at && $delivery->attempts >= 8, 409);
            DB::table('community_delivery_outbox')->where('id', $id)->update(['attempts' => 0, 'available_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            $this->audit->record($admin, 'delivery_retry', null, ['delivery_id' => $id, 'reason' => $reason]);
        });
    }
}
