<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\PresenceShare;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\EditorContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Sono qui»: un link per chi ti raggiunge a un evento.
 *
 * Mostra la posizione **del locale**, mai quella del telefono: chi lo riceve
 * sa dove andare, non dove sei. Si crea solo per una data che comincia entro
 * dodici ore o è in corso, scade da solo due ore dopo la fine e si revoca
 * quando si vuole. Il nome è quello che la persona sceglie di mostrare.
 */
final class PresenceController extends Controller
{
    public const WINDOW_HOURS = 12;

    public const AFTER_END_HOURS = 2;

    public function store(Request $request, ?EventOccurrence $occurrence = null): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'occurrence_id' => [$occurrence === null ? 'required' : 'sometimes', 'integer'],
            'display_name' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $occurrence ??= EventOccurrence::query()->findOrFail((int) $data['occurrence_id']);
        $start = CarbonImmutable::instance($occurrence->starts_at);
        $end = CarbonImmutable::instance($occurrence->effective_ends_at ?? $occurrence->ends_at ?? $occurrence->starts_at->copy()->addHours(3));
        if ($start->greaterThan(now()->addHours(self::WINDOW_HOURS)) || $end->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['occurrence_id' => __('presence.not_now')]);
        }
        PresenceShare::query()->where('user_id', $user->getKey())->where('occurrence_id', $occurrence->getKey())->active()->update(['revoked_at' => now()]);
        $name = trim((string) ($data['display_name'] ?? ''));
        $share = PresenceShare::query()->create([
            'user_id' => $user->getKey(),
            'occurrence_id' => $occurrence->getKey(),
            'token' => Str::random(32),
            'display_name' => $name === '' ? ($user->first_name ?: null) : EditorContent::clean('name', $name),
            'note' => filled($data['note'] ?? null) ? EditorContent::clean('note', (string) $data['note']) : null,
            'expires_at' => $end->addHours(self::AFTER_END_HOURS),
        ]);

        return $request->is('api/*')
            ? ApiResponse::item(['token' => $share->token, 'url' => $share->url(), 'expires_at' => $share->expires_at->toIso8601String()], status: 201)
            : back()->with('status', __('presence.created'));
    }

    public function destroy(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        PresenceShare::query()->where('user_id', $user->getKey())->where('token', $token)->update(['revoked_at' => now()]);

        return $request->is('api/*') ? response()->json(null, 204) : back()->with('status', __('presence.revoked'));
    }

    public function show(string $token): Response
    {
        $share = PresenceShare::query()->where('token', $token)->active()->with(['occurrence.event.city', 'occurrence.venue', 'occurrence.event.venue'])->first();
        abort_if($share === null || $share->occurrence === null || $share->occurrence->event === null, 404);

        return response()->view('presence.show', [
            'share' => $share,
            'meta' => new PageMeta(title: __('presence.page_title'), heading: __('presence.page_title'), indexable: false),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
