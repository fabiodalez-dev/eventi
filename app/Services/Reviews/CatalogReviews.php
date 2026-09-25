<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\CarpoolCaseStatus;
use App\Enums\CatalogReviewStatus;
use App\Enums\VenueStatus;
use App\Models\CarpoolCase;
use App\Models\CatalogRating;
use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolAuditLog;
use App\Services\Carpool\CommunityNotices;
use App\Services\Community\CommunityAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogReviews
{
    public function canWrite(?User $user, Venue|Organizer $subject): bool
    {
        return $user !== null && $user->canParticipateInCommunity() && ! $user->trashed() && ! session()->has('impersonator_id')
            && ($subject instanceof Venue ? $subject->status === VenueStatus::Approved && ! $subject->members()->whereKey($user->id)->exists()
                : $subject->is_active && ! $subject->managedBy($user));
    }

    /** @return Builder<CatalogReview> */
    public function published(Venue|Organizer $subject): Builder
    {
        return CatalogReview::query()->whereMorphedTo('reviewable', $subject)->where('approved', true)
            ->where('status', CatalogReviewStatus::Approved)->whereIn('user_id', app(CommunityAccess::class)->eligibleUsers()->select('id'));
    }

    /** @return array<string, mixed> */
    public function resource(CatalogReview $review): array
    {
        return ['id' => $review->id, 'author' => $review->user->communityProfile->display_name ?? $review->user->name ?? __('community.member'),
            'rating' => $review->rating, 'body' => $review->body, 'date' => $review->created_at?->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    public function listing(Venue|Organizer $subject, ?User $user, int $page = 1): array
    {
        $query = $this->published($subject);
        $stats = CatalogRating::where('key', 'overall')->whereIn('review_id', (clone $query)->select('id'))->selectRaw('COUNT(*) as total, AVG(value) as average')->first();
        $list = $query->with(['ratings', 'user.communityProfile'])->orderByDesc('moderated_at')->orderByDesc('id')->paginate(10, page: $page);
        $own = $user === null ? null : CatalogReview::whereMorphedTo('reviewable', $subject)->where('user_id', $user->id)->with('ratings')->first();

        return ['count' => $list->total(), 'rated_count' => (int) $stats?->getAttribute('total'),
            'average' => $stats?->getAttribute('average') === null ? null : round((float) $stats->getAttribute('average'), 1),
            'can_review' => $this->canWrite($user, $subject), 'verified' => $user?->canParticipateInCommunity() ?? false,
            'reviews' => $list->map(fn (CatalogReview $review): array => $this->resource($review))->all(), 'page' => $list->currentPage(), 'last_page' => $list->lastPage(),
            'my_review' => $own === null ? null : ['rating' => $own->rating, 'body' => $own->body, 'moderation_note' => $own->moderation_note, 'revision' => $own->revision, 'status' => $own->status->value]];
    }

    /**
     * Forma dell'API v1. L'app già installata dichiara voto e testo non nulli:
     * un solo `null` farebbe fallire la lettura dell'intera lista. Qui l'assenza
     * si scrive 0 e stringa vuota, che la nuova app tratta come «non dato».
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    public function apiListing(array $listing): array
    {
        $compatible = fn (array $review): array => ['rating' => $review['rating'] ?? 0, 'body' => $review['body'] ?? ''] + $review;
        $listing['reviews'] = array_map($compatible, $listing['reviews']);
        if (is_array($listing['my_review'])) {
            $listing['my_review'] = $compatible($listing['my_review']);
        }

        return $listing;
    }

    public function submit(Venue|Organizer $subject, User $user, ?int $rating, ?string $body, ?int $revision = null): CatalogReview
    {
        return DB::transaction(function () use ($subject, $user, $rating, $body, $revision): CatalogReview {
            $subject = $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canWrite($user, $subject), 403, __('reviews.verification_required'));
            // Services validate too: the package clamps out-of-range ratings, which would mask invalid input.
            validator(['rating' => $rating, 'body' => $body], ['rating' => ['nullable', 'required_without:body', 'integer', 'between:1,5'], 'body' => ['nullable', 'required_without:rating', 'string', 'min:10', 'max:3000']])->validate();
            $review = CatalogReview::whereMorphedTo('reviewable', $subject)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($revision !== null && ($review->revision ?? 0) !== $revision) {
                throw ValidationException::withMessages(['revision' => __('reviews.changed')]);
            }
            if ($review && $review->rating === $rating && $review->body === $body) {
                return $review;
            }
            $data = ['review' => $body, 'approved' => false, 'ratings' => $rating === null ? [] : ['overall' => $rating]];
            if ($review) {
                $subject->updateReview($review->id, $data);
                if ($rating === null) {
                    $review->ratings()->delete();
                }
            } else {
                $created = $subject->addReview($data, $user->id);
                $review = CatalogReview::findOrFail($created->getKey());
                $review->revision = 0;
            }
            $review->forceFill(['status' => CatalogReviewStatus::Pending, 'approved' => false, 'revision' => $review->revision + 1,
                'moderated_by' => null, 'moderated_at' => null, 'moderation_note' => null])->save();
            app(CarpoolAuditLog::class)->record($user, 'catalog_review_submitted', $review);

            return $review->fresh('ratings');
        }, 5);
    }

    public function remove(Venue|Organizer $subject, User $user): void
    {
        DB::transaction(function () use ($subject, $user): void {
            $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $review = CatalogReview::whereMorphedTo('reviewable', $subject)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            app(CarpoolAuditLog::class)->record($user, 'catalog_review_removed', $review);
            $subject->deleteReview($review->id);
        }, 5);
    }

    public function moderate(CatalogReview $review, User $admin, string $status, int $revision, ?string $note): void
    {
        app(CarpoolAccess::class)->notImpersonating();
        Gate::forUser($admin)->authorize('update', $review);
        $decision = CatalogReviewStatus::tryFrom($status);
        abort_unless(in_array($decision, [CatalogReviewStatus::Approved, CatalogReviewStatus::Rejected], true), 422);
        validator(['note' => $note], ['note' => [$decision === CatalogReviewStatus::Rejected ? 'required' : 'nullable', 'string', 'min:5', 'max:1000']])->validate();
        DB::transaction(function () use ($review, $admin, $decision, $revision, $note): void {
            $locked = CatalogReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => __('reviews.changed')]);
            }
            $locked->forceFill(['status' => $decision, 'approved' => $decision === CatalogReviewStatus::Approved, 'moderated_by' => $admin->id,
                'moderated_at' => now(), 'moderation_note' => $note, 'revision' => $locked->revision + 1])->save();
            app(CarpoolAuditLog::class)->record($admin, 'catalog_review_moderated', $locked, ['status' => $decision->value, 'reason' => $note]);
            if ($locked->user) {
                app(CommunityNotices::class)->send($locked->user, 'social', 'catalog_review_moderated', $locked->reviewable_type, $locked->reviewable_id, 'catalog-review:'.$locked->id.':'.$locked->revision);
            }
        }, 5);
    }

    public function report(User $user, CatalogReview $review, string $body): CarpoolCase
    {
        abort_unless($user->canParticipateInCommunity() && ! session()->has('impersonator_id'), 403);
        abort_unless($review->reviewable instanceof Venue || $review->reviewable instanceof Organizer, 404);
        abort_unless($this->published($review->reviewable)->whereKey($review->id)->exists(), 404);

        return DB::transaction(function () use ($user, $review, $body): CarpoolCase {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $case = CarpoolCase::where('reporter_id', $user->id)->where('social_type', 'catalog_review')->where('social_id', $review->id)->whereNull('closed_at')->first();
            if ($case) {
                return $case;
            }
            $case = CarpoolCase::create(['reporter_id' => $user->id, 'social_type' => 'catalog_review', 'social_id' => $review->id, 'reason' => 'offensive', 'body' => $body,
                'review_snapshot' => [...$this->resource($review), 'subject_type' => $review->reviewable_type, 'subject_id' => $review->reviewable_id, 'subject_name' => $review->reviewable->getAttribute('name')], 'status' => CarpoolCaseStatus::Open]);
            app(CarpoolAuditLog::class)->record($user, 'catalog_review_reported', $case);

            return $case;
        }, 5);
    }
}
