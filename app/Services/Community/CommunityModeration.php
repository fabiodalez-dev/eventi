<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\CommunityStatus;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CommunityModeration
{
    public function restoreRestriction(User $moderator, int $id): void
    {
        abort_unless($moderator->isEditorialStaff(), 403);
        DB::transaction(function () use ($moderator, $id): void {
            $restriction = DB::table('community_restrictions')->where('id', $id)->first();
            abort_unless($restriction !== null, 404);
            $user = User::query()->whereKey($restriction->user_id)->lockForUpdate()->firstOrFail();
            DB::table('community_restrictions')->where('id', $id)->delete();
            CommunityPost::query()->where('user_id', $user->id)->where('occurrence_id', $restriction->occurrence_id)->update(['status' => CommunityStatus::Published->value]);
            activity('community')->causedBy($moderator)->performedOn($user)->withProperties(['occurrence_id' => $restriction->occurrence_id])->event('restriction_restored')->log('restriction_restored');
        });
    }

    public function apply(User $moderator, string $kind, int $id, bool $enabled): void
    {
        abort_unless($moderator->isEditorialStaff(), 403);
        DB::transaction(function () use ($moderator, $kind, $id, $enabled): void {
            $model = match ($kind) {
                'post' => CommunityPost::query()->findOrFail($id),
                'comment' => CommunityComment::query()->findOrFail($id),
                'profile' => CommunityProfile::query()->findOrFail($id),
                'user' => User::query()->findOrFail($id),
                default => abort(422),
            };
            $userId = $model instanceof User ? $model->id : $model->user_id;
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $model->refresh();
            if ($model instanceof CommunityPost) {
                if (! $enabled) {
                    DB::table('community_restrictions')->updateOrInsert(['user_id' => $model->user_id, 'occurrence_id' => $model->occurrence_id], ['created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('community_restrictions')->where('user_id', $model->user_id)->where('occurrence_id', $model->occurrence_id)->delete();
                }
            }
            $attributes = match ($kind) {
                'profile' => ['featured' => $enabled],
                'user' => ['community_suspended_at' => $enabled ? null : now()],
                default => ['status' => $enabled ? CommunityStatus::Published : CommunityStatus::Hidden],
            };
            $model->forceFill($attributes)->save();
            activity('community')->causedBy($moderator)->performedOn($model)->withProperties(['kind' => $kind, 'enabled' => $enabled])->event('moderation')->log('moderation');
        });
    }
}
