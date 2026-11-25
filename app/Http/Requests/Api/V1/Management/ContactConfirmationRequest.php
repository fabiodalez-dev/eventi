<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\Organizer;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Reinvia la conferma» del recapito del modulo di contatto, per il locale o
 * l'organizzazione: lo può chiedere chi può cambiare quella scheda (il
 * referente del locale, chi ha creato l'organizzazione), come il pulsante
 * della sezione nei pannelli.
 */
final class ContactConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->target();

        return $this->user()?->can($target instanceof Venue ? 'update' : 'updateProfile', $target) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function target(): Venue|Organizer
    {
        return ManagementSubject::require()->model;
    }
}
