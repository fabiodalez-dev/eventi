<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Settings\UpdateNewsletterRequest;
use App\Models\User;
use App\Services\Editorial\EditorialSettings;
use App\Services\Settings\NewsletterConfiguration;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * La newsletter del fine settimana (R-SY-10), come la pagina «Newsletter»
 * del pannello: solo il super admin. Interruttore, giorno, ora, numero di
 * eventi e i quattro testi, con le regole di `NewsletterConfiguration`
 * (la stessa classe del pannello).
 */
final class NewsletterSettingsController extends Controller
{
    public function show(EditorialSettings $settings): JsonResponse
    {
        $this->superAdmin();

        return response()->json(['data' => $settings->newsletter()]);
    }

    public function update(UpdateNewsletterRequest $request, EditorialSettings $settings): JsonResponse
    {
        $user = $this->superAdmin();
        $values = $request->validated();
        $errors = [];
        foreach (NewsletterConfiguration::unknownVariables($values) as $field => $unknown) {
            $errors[$field] = __('editorial-settings.newsletter.unknown', ['tokens' => implode(', ', $unknown)]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        NewsletterConfiguration::save($values, $user);

        return response()->json([
            'data' => $settings->newsletter(),
            'meta' => ['message' => __('editorial-settings.newsletter.saved')],
        ]);
    }

    private function superAdmin(): User
    {
        $user = EditorialDesk::require()->user;
        abort_unless(NewsletterConfiguration::canManage($user), 403);

        return $user;
    }
}
