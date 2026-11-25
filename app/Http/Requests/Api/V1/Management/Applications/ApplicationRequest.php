<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Applications;

use App\Models\User;
use App\Models\VenueApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Una richiesta mandata dall'app (P13): sempre da collegati, con l'email già
 * confermata (`RequireConfirmedAccount` sulle rotte). Niente email nel modulo,
 * niente password, tempo minimo, campo esca né Turnstile: chi scrive è
 * l'account, e la richiesta risponde al suo indirizzo (decisione 8 del piano).
 *
 * I campi sono quelli del modulo del sito, dalle stesse regole
 * (`ApplicationRules`); le etichette nei messaggi d'errore pure.
 */
abstract class ApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && Gate::allows('create', VenueApplication::class);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.application.fields');

        return $fields;
    }

    public function applicant(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** L'email a cui la richiesta risponde: sempre quella dell'account. */
    public function applicantEmail(): string
    {
        return mb_strtolower(trim($this->applicant()->email));
    }
}
