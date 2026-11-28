<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * La campanella della redazione (R-DB-11): le notifiche che il pannello
 * `/admin` mostra nella sua campanella, cioè quelle scritte nella forma di
 * Filament (`data.format = filament`) nell'archivio della persona. Oggi è
 * l'avviso del mattino sulle sponsorizzazioni in ritardo o a rischio
 * (`sponsorships:pacing-check`, `SponsorshipPacingAlert`), che arriva a chi
 * ha `sponsorships.manage`: admin e super admin. Il moderatore non ne
 * riceve e trova la campanella vuota, come nel pannello.
 *
 * Le stesse operazioni della campanella di Filament
 * (`DatabaseNotifications`): segna come letta o da leggere, togli, segna
 * tutte come lette, svuota. Agiscono solo sulle notifiche di questa forma e
 * di questa persona: gli altri avvisi dell'account restano nella casella
 * dell'app.
 */
final class EditorialBell
{
    /** Come la tendina del pannello, che ne carica cinquanta per pagina. */
    public const PER_PAGE = 50;

    /** @return MorphMany<DatabaseNotification, User> */
    public function query(User $user): MorphMany
    {
        return $user->notifications()->where('data->format', 'filament');
    }

    public function unread(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /** `false` se la notifica non è di questa persona o non è della campanella. */
    public function markRead(User $user, string $id): bool
    {
        $query = $this->one($user, $id);

        return $query !== null && $query->update(['read_at' => now()]) > 0;
    }

    public function markUnread(User $user, string $id): bool
    {
        $query = $this->one($user, $id);

        return $query !== null && $query->update(['read_at' => null]) > 0;
    }

    public function remove(User $user, string $id): bool
    {
        $query = $this->one($user, $id);

        return $query !== null && (int) $query->delete() > 0;
    }

    public function find(User $user, string $id): ?DatabaseNotification
    {
        return $this->one($user, $id)?->first();
    }

    public function markAllRead(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function clear(User $user): int
    {
        return $this->query($user)->delete();
    }

    /**
     * Una riga della campanella: titolo, testo, colore (`warning`…), il
     * collegamento da aprire e le azioni con etichetta e indirizzo, come il
     * pannello le disegna. L'indirizzo resta quello del pannello
     * (`/admin/…`): l'app lo apre nella sua schermata nativa.
     *
     * @return array<string, mixed>
     */
    public static function row(DatabaseNotification $notification, string $timezone): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $actions = [];
        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            if (! is_array($action)) {
                continue;
            }
            $url = self::text($action['url'] ?? null);
            $label = self::text($action['label'] ?? null);
            if ($url !== null && $label !== null) {
                $actions[] = ['label' => $label, 'url' => $url];
            }
        }
        $created = $notification->getAttribute($notification->getCreatedAtColumn());
        $read = $notification->read_at;

        return [
            'id' => (string) $notification->getKey(),
            'title' => self::text($data['title'] ?? null) ?? '',
            'body' => self::text($data['body'] ?? null),
            'status' => self::text($data['status'] ?? null),
            'category' => self::text($data['category'] ?? null),
            'url' => self::text($data['url'] ?? null) ?? ($actions[0]['url'] ?? null),
            'actions' => $actions,
            'read' => $read !== null,
            'read_at' => $read instanceof CarbonInterface ? $read->copy()->setTimezone($timezone)->toIso8601String() : null,
            'created_at' => $created instanceof CarbonInterface ? $created->copy()->setTimezone($timezone)->toIso8601String() : null,
        ];
    }

    /**
     * Solo un id di notifica valido della campanella di questa persona, come i metodi di Filament.
     *
     * @return Builder<DatabaseNotification>|null
     */
    private function one(User $user, string $id): ?Builder
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var Builder<DatabaseNotification> $query */
        $query = $this->query($user)->where('id', $id)->getQuery();

        return $query;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
