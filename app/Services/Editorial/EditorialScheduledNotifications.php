<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\NotificationSkipReason;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Support\Notifications\ScheduledNotificationSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Gli invii previsti nell'app della redazione (R12, R-SY-05 e R-SY-06), come
 * la risorsa «Notifiche programmate» del pannello
 * (`ScheduledNotificationResource`): l'elenco con i filtri stato, tipo e
 * «in scadenza», la ricerca sull'email del destinatario (l'unica colonna
 * `searchable()`), l'ordinamento per orario di invio, e la riga.
 *
 * Da qui non si crea e non si modifica niente: l'unica azione è fermare un
 * invio che non è ancora partito (`ScheduledNotificationPolicy::cancel`), e
 * la riga resta con lo stato «annullata», come nel pannello.
 *
 * Il pannello vede gli invii di tutte le città: anche l'app.
 */
final class EditorialScheduledNotifications
{
    /** L'orario di invio è l'unica colonna ordinabile della tabella del pannello. */
    public const SORTS = ['send_at'];

    /**
     * @param  array{status?: string|null, type?: string|null, due?: bool}  $filters
     * @return LengthAwarePaginator<int, ScheduledNotification>
     */
    public function notifications(array $filters, ?string $search, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ScheduledNotification::query()->with(ScheduledNotificationSubject::relations());

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['type'] ?? null) !== null) {
            $query->where('type', $filters['type']);
        }
        if (($filters['due'] ?? false) === true) {
            ScheduledNotification::dueScope($query, CarbonImmutable::now());
        }
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('user', static fn (Builder $user): Builder => $user->where('email', 'like', $like));
        }

        if ($sort !== null && $sort !== '') {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        } else {
            $query->orderBy('send_at', 'desc');
        }
        $query->orderBy('id', 'desc');

        return $query->paginate($perPage, page: $page);
    }

    public function notification(int $id): ScheduledNotification
    {
        return ScheduledNotification::query()->with(ScheduledNotificationSubject::relations())->findOrFail($id);
    }

    /**
     * Una riga: le colonne della tabella del pannello, anche quelle nascoste
     * di partenza (canale, tentativi, inviata il, chiave di deduplica), e
     * l'azione permessa.
     *
     * @return array<string, mixed>
     */
    public function row(ScheduledNotification $notification, User $user): array
    {
        $reason = $notification->last_error;

        return [
            'id' => $notification->id,
            'send_at' => $notification->send_at->toIso8601String(),
            'type' => (string) $notification->type,
            'type_label' => $notification->type()?->label() ?? (string) $notification->type,
            'recipient' => $notification->user?->email,
            'subject' => ScheduledNotificationSubject::describe($notification),
            'status' => $notification->status->value,
            'status_label' => $notification->status->label(),
            'channel' => $notification->channel->value,
            'channel_label' => $notification->channel->label(),
            'attempts' => (int) $notification->attempts,
            'sent_at' => $notification->sent_at?->toIso8601String(),
            // Il motivo di un salto o l'errore dell'ultimo tentativo, già in parole come nel pannello.
            'reason' => $reason === null ? null : (NotificationSkipReason::tryFrom($reason)?->label() ?? $reason),
            'dedupe_key' => $notification->dedupe_key,
            'abilities' => [
                'cancel' => Gate::forUser($user)->allows('cancel', $notification),
            ],
        ];
    }
}
