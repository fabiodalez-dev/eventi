<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Comments\PostComment;
use App\Actions\Comments\ToggleReaction;
use App\Enums\EventCommentReactionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Comments\StoreEventCommentRequest;
use App\Models\City;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * I commenti alla scheda di un evento.
 *
 * ## Form normali, non chiamate JavaScript
 *
 * Ogni azione qui risponde a un `POST` di un form con `@csrf` e rimanda alla
 * pagina con un frammento — lo stesso schema delle recensioni dei locali.
 * Funziona con JavaScript spento, e il miglioramento progressivo (il
 * contatore che si aggiorna senza ricaricare) è uno strato sopra, non il
 * fondamento.
 *
 * Per questo le reazioni rispondono **due formati**: un `RedirectResponse` a
 * chi ha inviato il form, e un `JsonResponse` col nuovo conteggio a chi
 * intercetta l'invio col JavaScript. Il secondo non esiste senza il primo.
 */
class EventCommentController extends Controller
{
    use InteractsWithCity;

    public function store(StoreEventCommentRequest $request, string $slug, PostComment $azione): RedirectResponse
    {
        $city = $this->city();
        $event = $this->evento($city, $slug);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('create', EventComment::class);

        $padre = null;
        $padreId = $request->validated('parent_id');

        if ($padreId !== null) {
            /*
             * Il commento a cui si risponde deve stare su **questo** evento:
             * senza questa riga, un `parent_id` inventato attaccherebbe una
             * risposta alla conversazione di un altro evento.
             */
            $padre = EventComment::query()
                ->where('event_id', $event->id)
                ->published()
                ->findOrFail($padreId);
        }

        $azione->handle($event, $user, (string) $request->validated('body'), $padre);

        return redirect()
            ->route('events.show', ['slug' => $slug])
            ->withFragment('commenti')
            ->with('status', __('comments.submitted'));
    }

    public function destroy(Request $request, string $slug, EventComment $comment): RedirectResponse
    {
        $city = $this->city();
        $event = $this->evento($city, $slug);
        abort_unless($comment->event_id === $event->id, 404);

        Gate::forUser($request->user())->authorize('delete', $comment);
        $comment->delete();

        return redirect()
            ->route('events.show', ['slug' => $slug])
            ->withFragment('commenti')
            ->with('status', __('comments.deleted'));
    }

    /**
     * Mette, cambia o toglie una reazione.
     *
     * Chi arriva con JavaScript riceve il conteggio aggiornato e non ricarica
     * la pagina; chi arriva col form torna alla pagina, al punto giusto.
     */
    public function react(Request $request, string $slug, EventComment $comment, ToggleReaction $azione): RedirectResponse|JsonResponse
    {
        $city = $this->city();
        $event = $this->evento($city, $slug);
        abort_unless($comment->event_id === $event->id, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $tipo = EventCommentReactionType::tryFrom((string) $request->input('type'));
        abort_if($tipo === null, 422);

        $esito = $azione->handle($comment, $user, $tipo);

        if ($request->expectsJson()) {
            return response()->json([
                'stato' => $esito['stato'],
                'tipo' => $esito['tipo']?->value,
                'conteggio' => $esito['conteggio'],
            ])->header('Cache-Control', 'private, no-store');
        }

        return redirect()
            ->route('events.show', ['slug' => $slug])
            ->withFragment('commento-'.$comment->id);
    }

    private function evento(City $city, string $slug): Event
    {
        $event = Event::query()->inCity($city)->readable()->where('slug', $slug)->first();

        abort_if($event === null, 404);

        return $event;
    }
}
