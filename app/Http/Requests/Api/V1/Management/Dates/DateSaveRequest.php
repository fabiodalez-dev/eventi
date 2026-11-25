<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Enums\LineupRole;
use App\Enums\MembershipRequirement;
use App\Enums\OccurrenceStatus;
use App\Enums\VenueStatus;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Support\DeclaredCosts;
use Illuminate\Validation\Rule;

/**
 * Aggiungi o modifica una data. Senza `{occurrence}` nella rotta è una data
 * nuova (`EventOccurrencePolicy::create` sull'evento), altrimenti una
 * modifica (`EventOccurrencePolicy::update`, che ha anche il
 * collaboratore).
 *
 * I campi sono quelli del modulo del pannello del soggetto: il locale ha
 * posti, evidenza e lineup; l'organizzazione tutto il giorno, locale della
 * data, stato fra i cinque e avviso al pubblico. Le chiavi dell'altro modulo
 * si ignorano. Informazioni pratiche e costi valgono per entrambi.
 *
 * In modifica le chiavi assenti non si toccano; `practical_details` e
 * `cost_breakdown`, quando ci sono, sostituiscono l'intera parte salvata.
 */
final class DateSaveRequest extends ManagedDateRequest
{
    public function creating(): bool
    {
        return $this->route('occurrence') === null;
    }

    protected function allowed(User $user): bool
    {
        return $this->creating()
            ? $user->can('create', [EventOccurrence::class, $this->event()])
            : $user->can('update', $this->occurrence());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'starts_at' => [$this->creating() ? 'required' : 'sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            ...self::decisionRules(),
        ];

        return $this->subject()->isVenue()
            ? [...$rules, ...self::venueRules()]
            : [...$rules, ...self::organizerRules()];
    }

    /**
     * Informazioni pratiche e costi della data: le regole di
     * `OccurrenceDecisionFields` e dell'endpoint della biglietteria.
     *
     * @return array<string, list<mixed>>
     */
    public static function decisionRules(): array
    {
        $rules = [
            'practical_details' => ['nullable', 'array:accessibility,membership,entrance_notes,membership_notes,parking_notes,transit_notes,food_notes,start_notes'],
            'practical_details.accessibility' => ['nullable', Rule::in(['yes', 'no', 'unknown'])],
            'practical_details.membership' => ['nullable', Rule::in(array_column(MembershipRequirement::cases(), 'value'))],
            'cost_breakdown' => ['nullable', 'array:'.implode(',', DeclaredCosts::FIELDS)],
            'cost_breakdown.*' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
        ];
        foreach (['entrance_notes', 'membership_notes', 'parking_notes', 'transit_notes', 'food_notes', 'start_notes'] as $field) {
            $rules['practical_details.'.$field] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    private static function venueRules(): array
    {
        return [
            'capacity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'capacity_left' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'highlight' => ['nullable', 'string', 'max:40'],
            'lineup' => ['nullable', 'array', 'max:100'],
            'lineup.*' => ['array:id,name,role'],
            'lineup.*.id' => ['nullable', 'integer'],
            'lineup.*.name' => ['required', 'string', 'max:255'],
            'lineup.*.role' => ['required', Rule::in(LineupRole::values())],
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function organizerRules(): array
    {
        return [
            'is_all_day' => ['sometimes', 'boolean'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)],
            'status' => ['sometimes', 'required', Rule::in(OccurrenceStatus::values())],
            'status_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
