<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use App\Services\Account\ContentPreferences;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContentPreferencesController extends Controller
{
    public function index(Request $request, ContentPreferences $preferences): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = ['selection' => $preferences->selection($user), 'options' => Category::query()->active()->ordered()->get(['id', 'name'])];

        return $request->is('api/*') ? ApiResponse::item($data) : view('account.content-preferences', [
            ...$data,
            'meta' => new PageMeta(title: 'I miei interessi', heading: 'I miei interessi', indexable: false),
        ]);
    }

    public function update(Request $request, ContentPreferences $preferences): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (! $request->isJson()) {
            $choices = $request->validate(['choices' => ['sometimes', 'array', 'max:1000'], 'choices.*' => [Rule::in(['neutral', 'interested', 'hidden'])]])['choices'] ?? [];
            $request->merge(['categories' => array_keys(array_filter($choices, fn ($choice) => $choice === 'interested')), 'hidden_categories' => array_keys(array_filter($choices, fn ($choice) => $choice === 'hidden'))]);
            $request->merge(['categories' => $request->input('categories', []), 'hidden_categories' => $request->input('hidden_categories', []), 'inferred_ads' => $request->boolean('inferred_ads')]);
        }
        $data = $request->validate([
            'mode' => ['required', Rule::in(['all', 'selected'])],
            'categories' => ['present', 'array', 'max:1000'],
            'categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'hidden_categories' => ['present', 'array', 'max:1000'],
            'hidden_categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'inferred_ads' => ['required', 'boolean'],
        ]);
        if (array_intersect($data['categories'], $data['hidden_categories']) !== []) {
            throw ValidationException::withMessages(['categories' => 'Una categoria non può essere contemporaneamente preferita e nascosta.']);
        }
        $user->update(['content_preferences' => $data]);

        return $request->is('api/*') ? $this->index($request, $preferences) : to_route('account.content-preferences')->with('status', 'Interessi aggiornati. Puoi cambiarli quando vuoi.');
    }
}
