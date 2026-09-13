<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Contact\ContactRequest;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Contact\PublicContactService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class PublicContactController extends Controller
{
    use InteractsWithCity;

    private function target(string $type, string $slug): Venue|Organizer
    {
        return $type === 'venues'
            ? Venue::query()->inCity($this->city())->where('slug', $slug)->where('status', VenueStatus::Approved)->firstOrFail()
            : Organizer::query()->visibleInCity($this->city())->where('slug', $slug)->firstOrFail();
    }

    public function show(string $type, string $slug, PublicContactService $contact): JsonResponse
    {
        return ApiResponse::item($contact->settings($this->target($type, $slug)))->header('Cache-Control', 'no-store, private');
    }

    public function store(ContactRequest $request, string $type, string $slug, PublicContactService $contact): JsonResponse|RedirectResponse
    {
        $target = $this->target($type, $slug);
        $user = $request->user() ?? $request->user('sanctum');
        $contact->send($target, $user instanceof User ? $user : null, $request->validated());

        return $request->is('api/*') ? ApiResponse::item(['message' => __('contact.sent')])
            : redirect($contact->settings($target)['url'])->with('status', __('contact.sent'));
    }
}
