<?php

declare(strict_types=1);

namespace App\Http\Controllers\Community;

use App\DTOs\PageMeta;
use App\Enums\WhatsappChallengeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\WhatsappConfirmRequest;
use App\Http\Requests\Community\WhatsappRequest;
use App\Models\WhatsappChallenge;
use App\Services\Community\KapsoClient;
use App\Services\Community\WhatsappVerification;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WhatsappController extends Controller
{
    public function show(Request $request, KapsoClient $client): View|JsonResponse
    {
        $data = ['available' => $client->available(), 'verified' => $request->user()->isWhatsappVerified(),
            'challenge_id' => WhatsappChallenge::query()->where('user_id', $request->user()->id)->where('status', WhatsappChallengeStatus::Sent)->whereNull('consumed_at')->where('expires_at', '>', now())->orderByDesc('created_at')->value('id')];
        if ($request->expectsJson()) {
            return ApiResponse::item($data);
        }

        return view('community.whatsapp', [...$data, 'meta' => new PageMeta(__('community.whatsapp.title'), __('community.whatsapp.title'), indexable: false)]);
    }

    public function send(WhatsappRequest $request, WhatsappVerification $verification): JsonResponse|RedirectResponse
    {
        $challenge = $verification->request($request->user(), $request->string('phone')->toString(), $request->ip() ?? 'unknown');
        if ($request->expectsJson()) {
            return ApiResponse::item(['challenge_id' => $challenge->id, 'expires_at' => $challenge->expires_at->toIso8601String()]);
        }
        $request->session()->put('whatsapp_challenge', $challenge->id);

        return back()->with('status', __('community.whatsapp.sent'));
    }

    public function confirm(WhatsappConfirmRequest $request, WhatsappVerification $verification): JsonResponse|RedirectResponse
    {
        $verification->confirm($request->user(), $request->string('challenge_id')->toString(), $request->string('code')->toString());
        if ($request->expectsJson()) {
            return ApiResponse::item(['verified' => true]);
        }
        $request->session()->forget('whatsapp_challenge');

        return redirect()->route('community.settings')->with('status', __('community.whatsapp.done'));
    }

    public function revoke(Request $request, WhatsappVerification $verification): JsonResponse|RedirectResponse
    {
        $verification->revoke($request->user());
        if ($request->expectsJson()) {
            return ApiResponse::item(['verified' => false]);
        }

        return back()->with('status', __('community.whatsapp.revoked'));
    }

    public function skip(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->forceFill(['whatsapp_prompted_at' => now()])->save();

        if ($request->expectsJson()) {
            return ApiResponse::item(['prompted' => true]);
        }

        return redirect()->route('account.feed');
    }
}
