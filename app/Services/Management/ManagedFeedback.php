<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\EventCommentStatus;
use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Commenti e recensioni di un soggetto di gestione, per l'app (P7).
 *
 * Le regole non stanno qui: chi vede che cosa lo dice `ManagedContent`, la
 * stessa restrizione delle risorse `EventCommentResource` e
 * `CatalogReviewResource` dei pannelli; nascondere e ripristinare restano di
 * `ModerateComment`, rispondere di `CatalogReviews::reply`, segnalare di
 * `CatalogReviews::report`. Qui ci sono gli elenchi con i filtri del
 * pannello e la forma delle righe, con i permessi già calcolati perché
 * l'app mostri solo i pulsanti che il pannello mostrerebbe.
 */
final class ManagedFeedback
{
    public const PER_PAGE = 20;

    public function __construct(private readonly CatalogReviews $reviews) {}

    /**
     * I commenti agli eventi del soggetto, i più recenti prima, come la
     * tabella del pannello: filtro di stato, ricerca su evento, autore e
     * testo. In più il filtro per evento, per arrivare dalla scheda di un
     * evento ai soli commenti di quello.
     *
     * @return LengthAwarePaginator<int, EventComment>
     */
    public function comments(Venue|Organizer $subject, ?string $status, ?string $search, ?int $eventId, int $page): LengthAwarePaginator
    {
        $query = ManagedContent::for($subject)->comments()
            ->with(['event:id,title,slug,venue_id,organizer_id', 'event.organizer', 'user:id,name', 'moderator:id,name', 'moderator.roles'])
            ->withCount('reactions');

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($eventId !== null) {
            $query->where('event_id', $eventId);
        }
        if ($search !== null && $search !== '') {
            // Le tre colonne cercabili della tabella del pannello.
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $any) => $any->where('body', 'like', $like)
                ->orWhereHas('event', fn (Builder $event) => $event->where('title', 'like', $like))
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)));
        }

        return $query->orderByDesc('id')->paginate(self::PER_PAGE, page: max(1, $page));
    }

    /**
     * Una riga dell'elenco dei commenti. `can_hide` e `can_restore` sono i
     * pulsanti del pannello: «Nascondi» su un commento pubblicato, «Rimetti in
     * vista» su uno nascosto, sempre dietro la policy (un commento nascosto
     * dalla redazione non si rimette in vista da qui).
     *
     * @return array<string, mixed>
     */
    public function comment(EventComment $comment, User $user): array
    {
        $hidden = $comment->status === EventCommentStatus::Hidden;
        /** @var Event|null $event */
        $event = $comment->event;

        return [
            'id' => $comment->id,
            'event' => $event === null ? null : ['id' => $event->id, 'title' => $event->title, 'slug' => $event->slug],
            // Il pannello mostra il nome dell'account a chi gestisce: lo stesso qui.
            'author' => $comment->user->name,
            'body' => $comment->body,
            'is_reply' => $comment->parent_id !== null,
            'reactions_count' => (int) $comment->reactions_count,
            'status' => $comment->status->value,
            'status_label' => $comment->status->label(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'moderated_by' => $comment->moderator?->name,
            'moderated_at' => $comment->moderated_at?->toIso8601String(),
            'revision' => $comment->revision,
            'can_hide' => ! $hidden && $user->can('hide', $comment),
            'can_restore' => $hidden && $user->can('restore', $comment),
        ];
    }

    /**
     * Le recensioni pubblicate del soggetto, le più recenti prima, come la
     * pagina «Recensioni ricevute».
     *
     * @return LengthAwarePaginator<int, CatalogReview>
     */
    public function reviews(Venue|Organizer $subject, int $page): LengthAwarePaginator
    {
        return ManagedContent::for($subject)->reviews()
            ->with(['ratings', 'user.communityProfile', 'reviewable'])
            ->orderByDesc('moderated_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: max(1, $page));
    }

    /**
     * I permessi sulle recensioni di una pagina, chiesti una volta sola.
     *
     * L'elenco contiene solo recensioni pubblicate del soggetto, e per quelle
     * `CatalogReviewPolicy::reply` dà la stessa risposta a ogni riga (dipende
     * dalla persona e dal soggetto): chiederla per ognuna farebbe due query a
     * riga senza cambiare niente. Le scritture la richiedono comunque sulla
     * singola recensione.
     *
     * @param  iterable<CatalogReview>  $reviews
     * @return array{reply: bool, remove_reply: bool, report: bool}
     */
    public function reviewPermissions(iterable $reviews, User $user): array
    {
        $first = null;
        foreach ($reviews as $review) {
            $first = $review;
            break;
        }

        return [
            'reply' => $first !== null && $user->can('reply', $first),
            'remove_reply' => $first !== null && $user->can('removeReply', $first),
            // La condizione di `CatalogReviews::report`, come il pulsante «Segnala» della scheda pubblica.
            'report' => $user->canParticipateInCommunity() && ! session()->has('impersonator_id'),
        ];
    }

    /**
     * Una riga dell'elenco delle recensioni.
     *
     * @param  array{reply: bool, remove_reply: bool, report: bool}  $can
     * @return array<string, mixed>
     */
    public function review(CatalogReview $review, array $can): array
    {
        $public = $this->reviews->resource($review);
        $hasReply = filled($review->owner_reply);

        return [
            'id' => $review->id,
            'author' => $public['author'],
            'rating' => $review->rating,
            'body' => $review->body,
            'created_at' => $review->created_at?->toIso8601String(),
            'published_at' => $review->moderated_at?->toIso8601String(),
            'reply' => $hasReply ? [
                'body' => $review->owner_reply,
                'author' => (string) $review->reviewable?->getAttribute('name'),
                'replied_at' => $review->owner_replied_at?->toIso8601String(),
            ] : null,
            'can_reply' => $can['reply'],
            'can_remove_reply' => $hasReply && $can['remove_reply'],
            'can_report' => $can['report'],
        ];
    }

    /**
     * La pagina del pannello che modera i commenti o le recensioni del
     * soggetto, se la persona può entrarci: la scheda pubblica la passa
     * all'app, che la apre nella schermata nativa (o nel browser, se è una
     * versione che non la conosce). `event` restringe ai commenti di un evento.
     */
    public function panelUrl(Venue|Organizer $subject, ?User $user, string $page, ?int $event = null): ?string
    {
        if ($user === null || ! $user->canAccessTenant($subject)) {
            return null;
        }
        $panel = $subject instanceof Venue ? 'venue' : 'organizer';
        $resource = $page === 'reviews' ? 'recensioni' : 'event-comments';
        $query = $event === null ? [] : ['event' => $event];

        return route("filament.{$panel}.resources.{$resource}.index", ['tenant' => $subject, ...$query]);
    }

    /**
     * Chi modera i commenti di un evento dal suo pannello: il locale
     * dell'evento, altrimenti l'organizzazione che lo firma (non chi lo
     * co-organizza), come `ManagedContent::comments`.
     */
    public function commentsPanelUrl(Event $event, ?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $venue = $event->venue;
        if ($venue instanceof Venue && ($url = $this->panelUrl($venue, $user, 'comments', $event->id)) !== null) {
            return $url;
        }
        $organizer = $event->organizer;

        return $organizer instanceof Organizer ? $this->panelUrl($organizer, $user, 'comments', $event->id) : null;
    }
}
