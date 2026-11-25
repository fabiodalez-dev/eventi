<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\User;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * I collaboratori del locale (W-CO-01…05). Li vede e li cambia chi può
 * aprire la pagina «Collaboratori» del pannello: `manageCollaborators`, cioè
 * il referente, mai il collaboratore.
 */
final class CollaboratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageCollaborators', $this->venue());

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }

        /* Come il modulo «Invita un collaboratore»: nome ed email, il ruolo non si sceglie. */
        return $this->isMethod('post') && $this->route('member') === null ? [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ] : [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('manage.fields.name'),
            'email' => __('manage.fields.email'),
        ];
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** La persona della rotta, cercata solo fra le righe di questo locale (inviti compresi): 404 altrimenti. */
    public function member(): User
    {
        $id = $this->route('member');
        $member = is_numeric($id)
            ? $this->venue()->membersIncludingInvited()->whereKey((int) $id)->first()
            : null;
        abort_unless($member instanceof User, 404);

        return $member;
    }
}
