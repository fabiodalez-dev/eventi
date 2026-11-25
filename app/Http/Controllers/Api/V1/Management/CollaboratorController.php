<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\InviteVenueMemberAction;
use App\Enums\ApiErrorCode;
use App\Enums\VenueRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\CollaboratorRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\CollaboratorOutcome;
use App\Services\Management\VenueCollaborators;
use App\Support\Api\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * I collaboratori del locale (W-CO-01…05), con le regole della pagina
 * «Collaboratori» del pannello (`VenueCollaborators`): stessi inviti, stesso
 * tetto (10 l'ora e 30 al giorno, nello stesso secchio del sito), stesse
 * righe mascherate per chi non ha ancora accettato. Solo il referente.
 */
final class CollaboratorController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * Elenco delle persone del locale.
     *
     * Prima chi ha accettato, per nome; poi gli inviti in attesa, per email.
     * Un invito in attesa ha `name` nullo ed `email` mascherata: il nome è
     * quello dell'account, e il locale non lo vede finché la persona non dice
     * di sì. `q` cerca nel nome (solo fra chi ha accettato) e nell'email.
     */
    public function index(CollaboratorRequest $request, VenueCollaborators $collaborators): JsonResponse
    {
        $venue = $request->venue();
        $query = $collaborators->members($venue);
        $search = trim((string) $request->validated('q', ''));
        if ($search !== '') {
            $collaborators->search($query, $search);
        }
        $page = $collaborators->sorted($query)->paginate(self::PER_PAGE);
        $actor = $request->actor();

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $row): array => $this->row($venue, $row, $actor))->values()->all(),
            'meta' => [
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'invite_expires_days' => InviteVenueMemberAction::EXPIRES_DAYS,
                'invites_per_hour' => VenueCollaborators::INVITES_PER_HOUR,
                'invites_per_day' => VenueCollaborators::INVITES_PER_DAY,
            ],
        ]);
    }

    /**
     * Invita un collaboratore.
     *
     * Il ruolo è sempre collaboratore. 422 su `email` se la persona è già del
     * locale o ha già un invito in attesa (si reinvia dalla sua riga); 429
     * oltre il tetto degli inviti.
     */
    public function store(CollaboratorRequest $request, VenueCollaborators $collaborators): JsonResponse
    {
        $venue = $request->venue();
        $email = (string) $request->validated('email');
        $outcome = $collaborators->invite($venue, $request->actor(), $email, (string) $request->validated('name'));
        $this->fail($outcome);

        $row = $collaborators->members($venue)->where('users.email', mb_strtolower(trim($email)))->firstOrFail();

        return response()->json([
            'data' => $this->row($venue, $row, $request->actor()),
            'message' => __('manage.collaborators.invited_body', ['email' => $email]),
        ], 201);
    }

    /** Reinvia un invito in attesa: il collegamento precedente smette di valere. Conta nel tetto degli inviti. */
    public function resend(CollaboratorRequest $request, VenueCollaborators $collaborators): JsonResponse
    {
        $venue = $request->venue();
        $member = $request->member();
        $this->fail($collaborators->resend($venue, $request->actor(), $member));

        $row = $collaborators->members($venue)->whereKey($member->getKey())->firstOrFail();

        return response()->json([
            'data' => $this->row($venue, $row, $request->actor()),
            'message' => __('manage.collaborators.resent'),
        ]);
    }

    /** Ritira un invito in attesa. */
    public function withdraw(CollaboratorRequest $request, VenueCollaborators $collaborators): JsonResponse
    {
        $this->fail($collaborators->withdraw($request->venue(), $request->actor(), $request->member()));

        return response()->json(['data' => ['message' => __('manage.collaborators.withdrawn')]]);
    }

    /** Toglie un collaboratore che ha accettato; mai un referente. L'account e i suoi eventi restano. */
    public function destroy(CollaboratorRequest $request, VenueCollaborators $collaborators): JsonResponse
    {
        $this->fail($collaborators->remove($request->venue(), $request->actor(), $request->member()));

        return response()->json(['data' => ['message' => __('manage.collaborators.removed')]]);
    }

    /** Gli esiti che non sono riusciti diventano l'errore che l'app sa leggere. */
    private function fail(CollaboratorOutcome $outcome): void
    {
        match ($outcome) {
            /* Il motivo anche in `fields.invite`: il 429 generico si risolve aspettando un minuto, questo no. */
            CollaboratorOutcome::LimitReached => throw new ApiException(ApiErrorCode::RateLimited, __('manage.collaborators.invite_limit'), ['invite' => [__('manage.collaborators.invite_limit')]]),
            CollaboratorOutcome::AlreadyMember, CollaboratorOutcome::AlreadyInvited => throw new ApiException(
                ApiErrorCode::ValidationFailed,
                __('manage.collaborators.'.$outcome->value),
                ['email' => [__('manage.collaborators.'.$outcome->value)]],
            ),
            CollaboratorOutcome::NotAllowed => throw new ApiException(ApiErrorCode::Forbidden),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function row(Venue $venue, User $row, User $actor): array
    {
        $accepted = VenueCollaborators::accepted($row);
        $role = VenueRole::tryFrom((string) $row->getAttribute('venue_role')) ?? VenueRole::Editor;
        $timezone = (string) ($venue->city->timezone ?? config('app.timezone'));
        $pendingEditor = ! $accepted && $role === VenueRole::Editor;

        return [
            'id' => (int) $row->getKey(),
            'name' => $accepted ? (string) $row->name : null,
            'email' => $accepted ? (string) $row->email : VenueCollaborators::masked((string) $row->email),
            'role' => $role->value,
            'role_label' => $role->label(),
            'pending' => ! $accepted,
            'accepted_at' => $this->instant($row->getAttribute('venue_accepted_at'), $timezone),
            'invited_at' => $this->instant($row->getAttribute('venue_invited_at'), $timezone),
            'is_me' => (int) $row->getKey() === (int) $actor->getKey(),
            'can_resend' => $pendingEditor,
            'can_withdraw' => $pendingEditor,
            'can_remove' => $accepted && $role !== VenueRole::Owner,
        ];
    }

    private function instant(mixed $value, string $timezone): ?string
    {
        return $value === null ? null : ApiDate::instant(CarbonImmutable::parse((string) $value), $timezone);
    }
}
