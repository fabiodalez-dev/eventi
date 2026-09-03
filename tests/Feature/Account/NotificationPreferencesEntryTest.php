<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\DigestPlanner;
use Laravel\Pennant\Feature;

/**
 * La via per arrivare alle proprie preferenze **da dentro il sito**.
 *
 * Esisteva solo il collegamento firmato dentro le email — giusto per la
 * disiscrizione, che deve funzionare senza login — e questo chiudeva un
 * cerchio: il riepilogo del fine settimana richiede un consenso, il consenso
 * si da' da quella pagina, e a quella pagina si arrivava da un'email che non
 * si riceveva perche' mancava il consenso.
 */
it('porta un utente collegato alle proprie preferenze', function (): void {
    $utente = User::factory()->create();

    $risposta = $this->actingAs($utente)->get(route('account.notifications'));

    $risposta->assertRedirect();

    /* La firma resta l'unico modo di entrare, anche per chi ha fatto
       l'accesso: la pagina non si apre mai senza. */
    $destinazione = (string) $risposta->headers->get('Location');
    expect($destinazione)->toContain('signature=')
        ->and($destinazione)->toContain('/notifiche/preferenze/'.$utente->getKey());

    $this->actingAs($utente)->get($destinazione)->assertOk();
});

it('non apre le preferenze di qualcun altro', function (): void {
    $utente = User::factory()->create();
    $altro = User::factory()->create();

    $suo = (string) $this->actingAs($utente)
        ->get(route('account.notifications'))
        ->headers->get('Location');

    /* Cambiare il numero nell'indirizzo invalida la firma: e' la stessa
       barriera che protegge il collegamento dentro le email. */
    $altrui = str_replace(
        '/notifiche/preferenze/'.$utente->getKey(),
        '/notifiche/preferenze/'.$altro->getKey(),
        $suo,
    );

    $this->actingAs($utente)->get($altrui)->assertForbidden();
});

it('chiede di accedere a chi non lo ha fatto', function (): void {
    $this->get(route('account.notifications'))->assertRedirect(route('login'));
});

it('dal consenso alla email pianificata, tutto il giro', function (): void {
    Feature::for('global')->activate('newsletter');

    $utente = User::factory()->create(['marketing_opt_in_at' => null]);
    $indirizzo = (string) $this->actingAs($utente)
        ->get(route('account.notifications'))
        ->headers->get('Location');

    $inCoda = fn (): bool => ScheduledNotification::query()
        ->where('user_id', $utente->getKey())
        ->where('type', NotificationType::WeekendNewsletter->value)
        ->exists();

    /* Prima del consenso non deve esserci niente in coda. Senza questa
       verifica il test passerebbe anche se il consenso non contasse nulla e
       la newsletter partisse per tutti: direbbe «funziona» misurando la cosa
       sbagliata. */
    app(DigestPlanner::class)->plan();
    expect($inCoda())->toBeFalse();

    /* Si spunta la casella nella pagina vera, invece di scrivere la colonna a
       mano: e' il punto in cui il consenso viene dato, e un test che lo
       scrivesse direttamente non si accorgerebbe se quel modulo smettesse di
       salvarlo. */
    $this->actingAs($utente)
        ->patch(str_replace('/notifiche/preferenze/', '/notifiche/preferenze/', $indirizzo), [
            'marketing_opt_in' => '1',
        ])
        ->assertRedirect();

    expect($utente->fresh()->marketing_opt_in_at)->not->toBeNull();

    app(DigestPlanner::class)->plan();

    /* La prova che il cerchio si chiude: il riepilogo del fine settimana e'
       in coda per questa persona. Prima non poteva esserci per nessuno. */
    expect($inCoda())->toBeTrue();
});
