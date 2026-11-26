<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\CommunityStatus;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\User;
use App\Services\Community\CommunityModeration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Le letture della pagina «Profili e post» del pannello (`Community`,
 * R-CM-01): le quattro sezioni con la loro ricerca, in un posto solo per il
 * pannello e per l'app della redazione.
 *
 * Le scritture non stanno qui: restano di `CommunityModeration` (evidenza,
 * sospensione, nascondi e ripristina, restrizioni), che ricontrolla il
 * permesso e il rango a ogni chiamata. Qui ci sono le query della pagina,
 * identiche a quelle che il pannello faceva in `getViewData()`, e la forma
 * delle righe per l'API, con le `abilities`: i pulsanti che la riga del
 * pannello mostra, tolti quelli che il servizio rifiuterebbe comunque per
 * rango (sospendere o nascondere chi nello staff sta pari o sopra).
 *
 * Il pannello vede tutte le città in questa pagina, e così l'app.
 */
final class CommunityDesk
{
    /** Le schede della pagina, nell'ordine del pannello. */
    public const SECTIONS = ['profiles', 'posts', 'comments', 'restrictions'];

    /** La ricerca della pagina: fino a 80 caratteri, con `%` e `_` presi alla lettera. */
    public const SEARCH_MAX = 80;

    /**
     * La query di una sezione, già ordinata dalla più recente come il
     * pannello. Le restrizioni sono righe della tabella `community_restrictions`
     * con il nome della persona e il titolo e l'ora della serata.
     *
     * @return Builder<CommunityProfile>|Builder<CommunityPost>|Builder<CommunityComment>|QueryBuilder
     */
    public function query(string $section, string $search = ''): Builder|QueryBuilder
    {
        $needle = self::needle($search);
        if ($section === 'restrictions') {
            // Titolo e data dicono allo staff quale serata sta sbloccando; l'id resta come riferimento.
            return DB::table('community_restrictions')
                ->join('users', 'users.id', '=', 'community_restrictions.user_id')
                ->join('event_occurrences', 'event_occurrences.id', '=', 'community_restrictions.occurrence_id')
                ->join('events', 'events.id', '=', 'event_occurrences.event_id')
                ->join('cities', 'cities.id', '=', 'events.city_id')
                ->when($needle !== null, fn ($query) => $query->where('users.name', 'like', $needle))
                ->select('community_restrictions.*', 'users.name', 'events.title as event_title', 'event_occurrences.starts_at', 'cities.timezone')
                ->orderByDesc('community_restrictions.id');
        }
        $query = match ($section) {
            'posts' => CommunityPost::query()->with(['user.communityProfile', 'occurrence.event']),
            'comments' => CommunityComment::query()->with('user.communityProfile'),
            default => CommunityProfile::query()->with('user'),
        };
        if ($needle !== null) {
            $field = in_array($section, ['posts', 'comments'], true) ? 'body' : 'display_name';
            $query->where($field, 'like', $needle);
        }

        return $query->orderByDesc('id');
    }

    /** Il testo cercato come argomento di `like`, o `null` se la ricerca è vuota. */
    public static function needle(string $search): ?string
    {
        return $search !== '' ? '%'.addcslashes(mb_substr($search, 0, self::SEARCH_MAX), '%_\\').'%' : null;
    }

    // -------------------------------------------------------- righe dell'API

    /**
     * Una riga di qualunque sezione, con la stessa forma per tutte: l'app
     * disegna un elenco solo e legge `kind`.
     *
     * @return array<string, mixed>
     */
    public function row(string $section, mixed $item, User $staff): array
    {
        return match (true) {
            $section === 'restrictions' && $item instanceof stdClass => $this->restrictionRow($item),
            $item instanceof CommunityProfile => $this->profileRow($item, $staff),
            $item instanceof CommunityPost => $this->contentRow('post', $item, $staff),
            $item instanceof CommunityComment => $this->contentRow('comment', $item, $staff),
            default => [],
        };
    }

    /**
     * Un profilo: nome, nome utente, «in evidenza», «sospeso», biografia,
     * visibilità. Azioni: mettere o togliere dall'evidenza (sempre), sospendere
     * (solo chi nello staff sta sotto e non è già sospeso), riattivare.
     *
     * @return array<string, mixed>
     */
    public function profileRow(CommunityProfile $profile, User $staff): array
    {
        $person = $profile->user;
        $suspended = $person?->community_suspended_at !== null;

        return [
            'kind' => 'profile',
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'display_name' => $profile->display_name,
            'handle' => $profile->handle,
            'bio' => filled($profile->bio) ? $profile->bio : null,
            'visibility' => $profile->visibility->value,
            'visibility_label' => (string) __('community.profile_visibility.'.$profile->visibility->value),
            'featured' => (bool) $profile->featured,
            'suspended' => $suspended,
            'abilities' => [
                'feature' => true,
                // Data e tolta con la regola unica (`users.manage` e rango più alto).
                'suspend' => $person !== null && ! $suspended && CommunityModeration::canSuspendGlobally($staff, $person),
                'unsuspend' => $person !== null && $suspended && CommunityModeration::canSuspendGlobally($staff, $person),
            ],
        ];
    }

    /**
     * Una pubblicazione o un commento: autore (nome del profilo), testo,
     * «nascosto», per le pubblicazioni il titolo della serata. Azioni:
     * nascondere (chi sta sotto nello staff) o ripristinare.
     *
     * @return array<string, mixed>
     */
    public function contentRow(string $kind, CommunityPost|CommunityComment $item, User $staff): array
    {
        $published = $item->status === CommunityStatus::Published;
        $author = $item->user;

        return [
            'kind' => $kind,
            'id' => $item->id,
            'user_id' => $item->user_id,
            'author' => $author?->communityProfile?->display_name,
            'body' => (string) $item->body,
            'hidden' => ! $published,
            'event_title' => $item instanceof CommunityPost ? $item->occurrence?->event?->title : null,
            'abilities' => [
                'hide' => $published && $author !== null && CommunityModeration::outranks($staff, $author),
                'restore' => ! $published,
            ],
        ];
    }

    /**
     * Una serata bloccata: la persona, il titolo e l'inizio della serata (in
     * UTC, con il fuso della città per mostrarla come il pannello), l'id della
     * data come riferimento. Azione: togliere la restrizione.
     *
     * @return array<string, mixed>
     */
    public function restrictionRow(stdClass $item): array
    {
        $startsAt = isset($item->starts_at) ? Carbon::parse((string) $item->starts_at, 'UTC')->toIso8601String() : null;

        return [
            'kind' => 'restriction',
            'id' => (int) $item->id,
            'user_id' => (int) $item->user_id,
            'name' => (string) ($item->name ?? ''),
            'occurrence_id' => (int) $item->occurrence_id,
            'event_title' => (string) ($item->event_title ?? ''),
            'starts_at' => $startsAt,
            'timezone' => (string) ($item->timezone ?? 'Europe/Rome'),
            'abilities' => ['remove' => true],
        ];
    }

    /** Il profilo di una persona con quello che la riga legge (per rispondere dopo una sospensione). */
    public function profileOf(int $userId): ?CommunityProfile
    {
        return CommunityProfile::query()->with('user.roles')->where('user_id', $userId)->first();
    }

    /** Una pubblicazione o un commento con quello che la riga legge. */
    public function content(string $kind, int $id): CommunityPost|CommunityComment
    {
        return $kind === 'post'
            ? CommunityPost::query()->with(['user.communityProfile', 'user.roles', 'occurrence.event'])->findOrFail($id)
            : CommunityComment::query()->with(['user.communityProfile', 'user.roles'])->findOrFail($id);
    }
}
