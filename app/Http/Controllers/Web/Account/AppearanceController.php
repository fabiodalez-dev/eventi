<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Support\CardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AppearanceController extends Controller
{
    use InteractsWithAccount;

    public function index(): View
    {
        return view('account.appearance', [
            'meta' => new PageMeta(title: __('ui.appearance_page.title'), heading: __('ui.appearance_page.title'), description: __('ui.appearance_page.subtitle'), indexable: false),
        ]);
    }

    /**
     * Tre scelte: `dark`, `light` oppure `system`, che nel profilo diventa
     * `null` — nessuna preferenza, vale il tema del dispositivo. La chiave
     * deve esserci sempre: una richiesta senza scelta non è «segui il
     * sistema», è una richiesta sbagliata.
     */
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['appearance' => ['present', 'nullable', Rule::in(['dark', 'light', 'system'])]]);
        $user = $this->accountUser($request);
        $user->appearance = in_array($validated['appearance'], ['dark', 'light'], true) ? $validated['appearance'] : null;
        $user->save();

        return $request->expectsJson()
            ? response()->json(['appearance' => $user->appearance])->header('Cache-Control', 'private, no-store')
            : redirect()->route('appearance')->with('status', __('account.appearance.saved'));
    }

    /**
     * La vista degli elenchi sul telefono, salvata dal selettore delle pagine
     * elenco (`resources/js/card-layout.js`) per chi è collegato: la ritrova
     * su ogni dispositivo e nell'app. Solo JSON: il selettore non è un modulo.
     */
    public function updateCardLayout(Request $request): JsonResponse
    {
        $validated = $request->validate(['card_layout' => ['required', Rule::in(CardLayout::VALUES)]]);
        $user = $this->accountUser($request);
        $user->card_layout = $validated['card_layout'];
        $user->save();

        return response()->json(['card_layout' => $user->card_layout])->header('Cache-Control', 'private, no-store');
    }
}
