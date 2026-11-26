<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\MeQueryRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use App\Services\Management\ManagedContent;
use App\Support\Api\ApiResponse;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/**
 * Notifiche e chiusura del giro (P12).
 *
 * - `GET S/notifications`: gli avvisi a chi gestisce (evento pubblicato o
 *   rifiutato, nuovo commento, locale fermo da tempo) di questo locale o di
 *   questa organizzazione, presi dall'archivio dell'account con la stessa
 *   forma di `GET /me/notifications`. Sono le righe che portano
 *   `management` (`ManagementNoticeTarget`); quelle scritte prima restano
 *   nella casella generica dell'account. Con Q3 ci sono anche gli avvisi
 *   che erano solo email (ordine deciso, riepilogo della sponsorizzazione,
 *   rapporto mensile, locale moderato, accesso concesso, richiesta accolta)
 *   quando portano a questo soggetto; quelli dell'account (richiesta
 *   rifiutata, invito da accettare) restano nella casella dell'account.
 * - `GET S/events/by-slug/{slug}`: l'id di un evento del soggetto dal suo
 *   slug, per aprire nella schermata nativa un indirizzo del pannello
 *   (`/gestione/{locale}/eventi/{slug}`), che porta lo slug e non l'id.
 */
final class ManagementNoticeController extends Controller
{
    public function index(MeQueryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $subject = ManagementSubject::require();
        $types = array_values(array_map(
            static fn (NotificationType $type): string => $type->value,
            array_filter(NotificationType::cases(), static fn (NotificationType $type): bool => $type->opensManagement()),
        ));
        $query = static fn () => $user->notifications()
            ->whereIn('type', $types)
            ->where('data->management->subject_type', $subject->type())
            ->where('data->management->subject_id', (int) $subject->model->getKey());

        $paginator = $query()->cursorPaginate($request->limit(), cursor: $request->cursor())->withQueryString();
        $timezone = (string) $user->timezone;

        return ApiResponse::page(
            $paginator,
            static fn (DatabaseNotification $notification): array => NotificationResource::toArray($notification, $timezone),
            ['unread_count' => $query()->whereNull('read_at')->count()],
        );
    }

    /* Lo slug si legge dalla rotta e non da un argomento: il primo parametro della rotta è il soggetto. */
    public function eventBySlug(Request $request): JsonResponse
    {
        $this->user($request);
        $event = ManagedContent::current()->events()->where('events.slug', (string) $request->route('slug'))->orderBy('events.id')->firstOrFail();
        Gate::authorize('view', $event);

        return response()->json(['data' => ['id' => (int) $event->getKey(), 'slug' => (string) $event->slug, 'title' => (string) $event->title]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
