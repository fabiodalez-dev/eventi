<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
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
            'meta' => new PageMeta(title: 'Aspetto', heading: 'Aspetto', description: 'Scegli come vivere inCittà.', indexable: false),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['appearance' => ['required', Rule::in(['dark', 'light'])]]);
        $user = $this->accountUser($request);
        $user->appearance = $validated['appearance'];
        $user->save();

        return $request->expectsJson()
            ? response()->json(['appearance' => $user->appearance])->header('Cache-Control', 'private, no-store')
            : redirect()->route('appearance')->with('status', 'Aspetto salvato.');
    }
}
