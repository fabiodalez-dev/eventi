<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ConsentState;
use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Models\ConsentLog;
use App\Support\Consent;
use Illuminate\Support\Str;

/**
 * Registra una scelta sul consenso: scrive la riga di prova richiesta da §16 e
 * restituisce lo stato da mettere nel cookie.
 *
 * L'identificativo del browser si **riusa** se esiste già: è ciò che permette
 * di rileggere il registro come la storia di una scelta — accettata a
 * settembre, revocata a novembre — invece che come due scelte scollegate di due
 * sconosciuti.
 */
final class RecordConsent
{
    public function __construct(private readonly Consent $consent) {}

    /**
     * @param  list<ConsentCategory>  $accepted
     */
    public function handle(ConsentAction $action, array $accepted, ?int $userId = null): ConsentState
    {
        $choices = [];

        foreach (ConsentCategory::cases() as $category) {
            $choices[$category->value] = $category === ConsentCategory::Necessary
                || in_array($category, $accepted, true);
        }

        $previous = $this->consent->state();

        $state = new ConsentState(
            id: $previous === null ? (string) Str::uuid() : $previous->id,
            version: $this->consent->version(),
            choices: $choices,
        );

        ConsentLog::query()->create([
            'consent_id' => $state->id,
            'user_id' => $userId,
            'action' => $action,
            'choices' => $choices,
            'policy_version' => $state->version,
        ]);

        /*
         * La risposta a questa richiesta deve già comportarsi secondo la scelta
         * appena espressa: il cookie parte adesso, e il browser lo rimanderà
         * indietro solo alla richiesta successiva.
         */
        $this->consent->remember($state);

        return $state;
    }
}
