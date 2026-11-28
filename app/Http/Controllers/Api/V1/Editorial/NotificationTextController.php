<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Settings\PreviewNotificationTextRequest;
use App\Http\Requests\Api\V1\Editorial\Settings\UpdateNotificationTextRequest;
use App\Models\User;
use App\Services\Editorial\EditorialSettings;
use App\Services\Settings\NotificationTextEditor;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * I testi delle email (R-SY-08), come la pagina «Testi delle email» del
 * pannello: admin e super admin (il moderatore riceve 403); i testi della
 * newsletter li vede solo il super admin. Le regole stanno in
 * `NotificationTextEditor`, la stessa classe del pannello.
 *
 * Il pannello salva tutto il modulo insieme; dall'app si salva un testo per
 * volta, con la revisione letta (decisione 7: se nel frattempo qualcuno l'ha
 * cambiato, 422 su `revision`). Le regole del salvataggio sono le stesse:
 * vuoto o uguale all'originale torna all'originale, nessun segnaposto che
 * l'originale non ha.
 */
final class NotificationTextController extends Controller
{
    public function index(EditorialSettings $settings): JsonResponse
    {
        $user = $this->editor();

        return response()->json(['data' => $settings->texts($user)]);
    }

    /** L'esempio con i dati dimostrativi di un testo non ancora salvato, e i segnaposti che non vanno. */
    public function preview(PreviewNotificationTextRequest $request): JsonResponse
    {
        $user = $this->editor();
        $key = (string) $request->validated('key');
        abort_unless(NotificationTextEditor::editable($user, $key), 404);
        $value = (string) ($request->validated('value') ?? '');

        return response()->json(['data' => [
            'key' => $key,
            'preview' => NotificationTextEditor::preview($key, $value),
            'unknown' => NotificationTextEditor::unknownVariables($user, [$key => $value])[$key] ?? [],
        ]]);
    }

    public function update(UpdateNotificationTextRequest $request, string $key, EditorialSettings $settings): JsonResponse
    {
        $user = $this->editor();
        abort_unless(NotificationTextEditor::editable($user, $key), 404);

        if (! hash_equals(NotificationTextEditor::revision($key), (string) $request->validated('revision'))) {
            throw ValidationException::withMessages(['revision' => __('editorial-settings.texts.changed')]);
        }

        $value = (string) ($request->validated('value') ?? '');
        $unknown = NotificationTextEditor::unknownVariables($user, [$key => $value])[$key] ?? [];
        if ($unknown !== []) {
            throw ValidationException::withMessages(['value' => NotificationTextEditor::invalidMessage($unknown)]);
        }

        $result = NotificationTextEditor::save($user, [$key => $value]);

        return response()->json([
            'data' => $settings->text($user, $key),
            'meta' => ['message' => __('notification_texts.saved', $result)],
        ]);
    }

    /** «Riporta tutto all'originale»: le riscritture che la persona vede tornano ai testi di partenza. */
    public function reset(EditorialSettings $settings): JsonResponse
    {
        $user = $this->editor();
        NotificationTextEditor::resetAll($user);

        return response()->json([
            'data' => $settings->texts($user),
            'meta' => ['message' => __('notification_texts.reset_done')],
        ]);
    }

    private function editor(): User
    {
        $user = EditorialDesk::require()->user;
        abort_unless(NotificationTextEditor::canEdit($user), 403);

        return $user;
    }
}
