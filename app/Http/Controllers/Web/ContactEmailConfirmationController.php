<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Contact\ContactEmailConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Il collegamento che conferma il recapito del modulo contatti.
 *
 * La firma e la scadenza le verifica il middleware `signed`. La GET mostra
 * solo la pagina con il pulsante e non conferma niente: gli scanner dei
 * client di posta aprono i collegamenti da soli, e una conferma deve venire
 * da una persona. Conferma il POST, con CSRF, verso lo stesso indirizzo.
 */
final class ContactEmailConfirmationController extends Controller
{
    public function show(Request $request, string $type, int $id): View
    {
        $target = $this->target($type, $id);
        $valid = hash_equals(ContactEmailConfirmation::fingerprint((string) $target->contact_email), (string) $request->query('email'));

        return $this->page($valid ? 'pending' : 'invalid', $target, $request->fullUrl());
    }

    public function store(Request $request, string $type, int $id, ContactEmailConfirmation $confirmation): View
    {
        $target = $this->target($type, $id);

        return $this->page($confirmation->confirm($target, (string) $request->query('email')) ? 'confirmed' : 'invalid', $target, null);
    }

    private function target(string $type, int $id): Venue|Organizer
    {
        return $type === 'venues' ? Venue::query()->findOrFail($id) : Organizer::query()->findOrFail($id);
    }

    private function page(string $state, Venue|Organizer $target, ?string $action): View
    {
        $title = __('contact.confirmation.page_title');

        return view('contact.confirm-email', [
            'state' => $state,
            'name' => (string) $target->name,
            'action' => $action,
            'meta' => new PageMeta(title: $title, heading: $title, indexable: false),
        ]);
    }
}
