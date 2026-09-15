<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RememberLocationRequest;
use App\Services\RememberedLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RememberedLocationController extends Controller
{
    public function show(Request $request, RememberedLocation $locations): JsonResponse
    {
        return response()->json(['data' => $locations->resolve($request)])->header('Cache-Control', 'private, no-store');
    }

    public function store(RememberLocationRequest $request, RememberedLocation $locations): JsonResponse
    {
        $position = $locations->save((float) $request->validated('lat'), (float) $request->validated('lng'), $request->user(), $request->validated('observed_at') !== null ? (int) $request->validated('observed_at') : null);
        $response = response()->json(['data' => $position])->header('Cache-Control', 'private, no-store');
        if (! $request->is('api/*')) {
            $response->cookie(RememberedLocation::COOKIE, json_encode($position), (int) ceil(($position['expires_at'] - now()->timestamp) / 60), '/', null, $request->isSecure(), true, false, 'lax');
        }

        return $response;
    }

    public function destroy(Request $request, RememberedLocation $locations): JsonResponse
    {
        $locations->forget($request->user());

        return response()->json(['data' => null])->withoutCookie(RememberedLocation::COOKIE)
            ->header('Cache-Control', 'private, no-store');
    }
}
