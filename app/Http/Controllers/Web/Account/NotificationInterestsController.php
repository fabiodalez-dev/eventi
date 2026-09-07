<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\Account\UpdateInterestsRequest;
use App\Models\User;
use App\Services\Account\NotificationInterests;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NotificationInterestsController extends Controller
{
    use InteractsWithCity;

    public function index(Request $request, NotificationInterests $interests): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $options = $interests->options($user, $this->city());
        if ($request->is('api/*')) {
            return ApiResponse::item($options);
        }

        return view('account.notifications.interests', [
            ...$options,
            'meta' => new PageMeta(title: __('subscriptions.interests'), heading: __('subscriptions.interests'), indexable: false),
        ]);
    }

    public function update(UpdateInterestsRequest $request, NotificationInterests $interests): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $interests->update($user, $this->city(), $request->validated());
        if ($request->is('api/*')) {
            return ApiResponse::item($interests->options($user, $this->city()));
        }

        return to_route('account.notifications.interests')->with('status', __('notifications.preferences.saved'));
    }
}
