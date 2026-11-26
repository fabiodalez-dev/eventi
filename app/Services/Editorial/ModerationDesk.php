<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\CatalogReviewStatus;
use App\Enums\EventCommentStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Report;
use App\Models\User;
use App\Queries\EditorialDashboardQuery;
use App\Support\Moderation\ReportSubject;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * La moderazione dei contenuti nell'app della redazione (R6, R-MD-01…12):
 * gli elenchi delle tre risorse del gruppo «Moderazione» del pannello
 * (`ReportResource`, `CatalogReviewResource`, `EventCommentResource`) e la
 * forma delle loro righe.
 *
 * Le regole non stanno qui. Chi può fare che cosa lo dicono le policy
 * (`ReportPolicy`, `CatalogReviewPolicy`, `EventCommentPolicy`), e le
 * scritture restano delle azioni che usa anche il pannello: `ResolveReport`,
 * `CatalogReviews::moderate` e `reply`, `ModerateComment`. Qui ci sono i
 * filtri, le ricerche e gli ordinamenti delle tabelle del pannello, e le
 * `abilities` di ogni riga: gli stessi pulsanti che il pannello mostrerebbe.
 *
 * Il pannello vede tutte le città in queste tre pagine: anche l'app.
 */
final class ModerationDesk
{
    /** Gli ordinamenti che le tabelle del pannello permettono (`sortable()`), per l'API `sort=campo|-campo`. */
    public const REPORT_SORTS = ['created_at'];

    public const REVIEW_SORTS = ['created_at'];

    public const COMMENT_SORTS = ['reactions_count'];

    /** I tipi recensibili, come il filtro «Tipo» del pannello. */
    public const REVIEW_TYPES = ['venue', 'organizer'];

    // ------------------------------------------------------------ segnalazioni

    /**
     * Le segnalazioni, dalla più recente, con i tre filtri del pannello:
     * stato, motivo e «aperte» (da esaminare o in esame). Il pannello non ha
     * una ricerca su questa tabella, e nemmeno l'API.
     *
     * @param  array{status?: string|null, reason?: string|null, open?: bool}  $filters
     * @return LengthAwarePaginator<int, Report>
     */
    public function reports(array $filters, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Report::query()->with(['reportable', 'reviewer:id,name']);

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['reason'] ?? null) !== null) {
            $query->where('reason', $filters['reason']);
        }
        if (($filters['open'] ?? false) === true) {
            EditorialDashboardQuery::openReportScope($query);
        }

        $this->sort($query, $sort, 'created_at', 'desc');

        return $query->paginate($perPage, page: $page);
    }

    /** Una segnalazione per il dettaglio, con quello che la riga mostra. */
    public function report(int $id): Report
    {
        return Report::query()->with(['reportable', 'reviewer:id,name'])->findOrFail($id);
    }

    /**
     * Una riga delle segnalazioni: le colonne della tabella del pannello più
     * l'esito del modulo «Modifica» (stato e nota, con chi e quando). Il
     * contatto e l'indirizzo IP di chi ha segnalato non escono: il pannello
     * non li mostra.
     *
     * @return array<string, mixed>
     */
    public function reportRow(Report $report, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => $report->id,
            'created_at' => $report->created_at?->toIso8601String(),
            'reason' => $report->reason->value,
            'reason_label' => $report->reason->label(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'subject' => [
                'type' => $report->reportable_type,
                'id' => $report->reportable_id,
                'label' => ReportSubject::describe($report),
                // «Modera il soggetto» del pannello: apre la pagina Community.
                'community' => ReportSubject::isCommunity($report),
            ],
            'note' => $report->note,
            'resolution_note' => $report->resolution_note,
            'reviewed_by' => $report->reviewer?->name,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'abilities' => [
                'resolve' => $gate->allows('update', $report),
                'destroy' => $gate->allows('delete', $report),
            ],
        ];
    }

    // -------------------------------------------------------------- recensioni

    /**
     * Le recensioni di locali e organizzatori, dall'ultima toccata, con i
     * filtri del pannello (tipo e stato) e la ricerca sul nome dell'autore,
     * l'unica colonna `searchable()` della tabella.
     *
     * @param  array{type?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, CatalogReview>
     */
    public function reviews(array $filters, ?string $search, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = CatalogReview::query()->with(['ratings', 'reviewable', 'user:id,name', 'moderator:id,name']);

        if (($filters['type'] ?? null) !== null) {
            $query->where('reviewable_type', $filters['type']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if ($search !== null && $search !== '') {
            $like = self::like($search);
            $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', $like));
        }

        $this->sort($query, $sort, 'updated_at', 'desc');

        return $query->paginate($perPage, page: $page);
    }

    public function review(int $id): CatalogReview
    {
        return CatalogReview::query()->with(['ratings', 'reviewable', 'user:id,name', 'moderator:id,name'])->findOrFail($id);
    }

    /**
     * Una riga delle recensioni. `approve` e `reject` sono i due pulsanti del
     * pannello (su ogni recensione, anche già decisa: chi modera può tornare
     * sulla decisione), `remove_reply` c'è solo quando c'è una risposta.
     *
     * @return array<string, mixed>
     */
    public function reviewRow(CatalogReview $review, User $user): array
    {
        $gate = Gate::forUser($user);
        $moderate = $gate->allows('update', $review);
        $hasReply = filled($review->owner_reply);
        $type = (string) $review->reviewable_type;

        return [
            'id' => $review->id,
            'subject' => [
                'type' => $type,
                'type_label' => (string) __('reviews.types.'.$type),
                'id' => $review->reviewable_id,
                'name' => (string) $review->reviewable?->getAttribute('name'),
            ],
            // Il pannello mostra alla redazione il nome dell'account: lo stesso qui.
            'author' => $review->user?->name,
            'rating' => $review->rating,
            'body' => $review->body,
            'status' => $review->status->value,
            'status_label' => (string) __('reviews.'.$review->status->value),
            'created_at' => $review->created_at?->toIso8601String(),
            'moderated_by' => $review->moderator?->name,
            'moderated_at' => $review->moderated_at?->toIso8601String(),
            'moderation_note' => $review->moderation_note,
            'revision' => $review->revision,
            'reply' => $hasReply ? [
                'body' => $review->owner_reply,
                'replied_at' => $review->owner_replied_at?->toIso8601String(),
            ] : null,
            'abilities' => [
                'approve' => $moderate,
                'reject' => $moderate,
                'remove_reply' => $hasReply && $gate->allows('removeReply', $review),
            ],
        ];
    }

    // ---------------------------------------------------------------- commenti

    /**
     * I commenti agli eventi, dal più recente, con il filtro di stato del
     * pannello e la ricerca sulle sue tre colonne `searchable()`: titolo
     * dell'evento, nome dell'autore, testo.
     *
     * @param  array{status?: string|null}  $filters
     * @return LengthAwarePaginator<int, EventComment>
     */
    public function comments(array $filters, ?string $search, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->commentQuery();

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if ($search !== null && $search !== '') {
            $like = self::like($search);
            $query->where(fn (Builder $any) => $any->where('body', 'like', $like)
                ->orWhereHas('event', fn (Builder $event) => $event->where('title', 'like', $like))
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)));
        }

        $this->sort($query, $sort, 'id', 'desc');

        return $query->paginate($perPage, page: $page);
    }

    public function comment(int $id): EventComment
    {
        return $this->commentQuery()->findOrFail($id);
    }

    /**
     * Una riga dei commenti: locandina, evento, autore, testo, stato, «Mi
     * piace» e chi ha moderato, come la riga del pannello, più la nota e la
     * data della moderazione del riquadro «Dettagli». Le azioni seguono il
     * pannello: «Nascondi» sui pubblicati, «Ripristina» sui nascosti,
     * «Elimina» solo a chi la policy lo permette (admin e super admin).
     *
     * @return array<string, mixed>
     */
    public function commentRow(EventComment $comment, User $user): array
    {
        $gate = Gate::forUser($user);
        $hidden = $comment->status === EventCommentStatus::Hidden;
        /** @var Event|null $event */
        $event = $comment->event;

        return [
            'id' => $comment->id,
            'event' => $event === null ? null : [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'poster' => PosterResource::toArray($event),
            ],
            'author' => $comment->user?->name,
            'body' => $comment->body,
            'is_reply' => $comment->parent_id !== null,
            'status' => $comment->status->value,
            'status_label' => $comment->status->label(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'reactions_count' => (int) $comment->reactions_count,
            'moderated_by' => $comment->moderator?->name,
            'moderated_at' => $comment->moderated_at?->toIso8601String(),
            'moderation_note' => $comment->moderation_note,
            'revision' => $comment->revision,
            'abilities' => $this->commentAbilities($gate, $comment, $hidden),
        ];
    }

    /** @return array{show: bool, hide: bool, restore: bool, destroy: bool} */
    private function commentAbilities(GateContract $gate, EventComment $comment, bool $hidden): array
    {
        return [
            // «Dettagli» del pannello chiede la stessa policy di «Nascondi».
            'show' => $gate->allows('hide', $comment),
            'hide' => $comment->status === EventCommentStatus::Published && $gate->allows('hide', $comment),
            'restore' => $hidden && $gate->allows('restore', $comment),
            'destroy' => $gate->allows('delete', $comment),
        ];
    }

    /** @return Builder<EventComment> */
    private function commentQuery(): Builder
    {
        /* Locandina, autore e moderatore nella stessa query, come la tabella del pannello. */
        return EventComment::query()->with(['event.media', 'user:id,name', 'moderator:id,name'])->withCount('reactions');
    }

    // --------------------------------------------------------------- in comune

    /**
     * `sort=campo` o `sort=-campo` fra quelli ammessi (li controlla la
     * richiesta), altrimenti l'ordinamento predefinito del pannello. L'id
     * chiude sempre l'ordine: due righe con la stessa data non cambiano
     * posto fra una pagina e l'altra.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function sort(Builder $query, ?string $sort, string $default, string $direction): void
    {
        if ($sort !== null && $sort !== '') {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        } else {
            $query->orderBy($default, $direction);
        }
        if ($default !== 'id' || ($sort !== null && $sort !== '')) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
        }
    }

    /**
     * La `meta` della convenzione degli elenchi della redazione.
     *
     * @param  LengthAwarePaginator<int, covariant \Illuminate\Database\Eloquent\Model>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    public static function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private static function like(string $search): string
    {
        return '%'.addcslashes($search, '%_\\').'%';
    }

    /**
     * Gli stati di una recensione, per il filtro «Stato».
     *
     * @return list<string>
     */
    public static function reviewStatuses(): array
    {
        return array_map(static fn (CatalogReviewStatus $status): string => $status->value, CatalogReviewStatus::cases());
    }
}
