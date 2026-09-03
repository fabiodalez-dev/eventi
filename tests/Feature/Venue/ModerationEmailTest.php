<?php

declare(strict_types=1);

use App\Actions\ModerateVenueAction;
use App\Enums\VenueStatus;
use App\Models\NotificationText;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueModerated;
use Illuminate\Support\Facades\Notification;

/**
 * Le email che avvisano un locale di cosa e' stato deciso, e la possibilita'
 * di riscriverle dal pannello.
 *
 * Prima non partiva niente: si approvava un locale e il referente lo scopriva
 * guardando il sito, se gli veniva in mente di guardare.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->venue = Venue::factory()->pending()->create();
    $this->referente = User::factory()->create();
    $this->venue->members()->attach($this->referente, ['role' => 'owner']);
    $this->moderatore = User::factory()->create();
});

it('avvisa il referente quando il locale viene approvato', function (): void {
    app(ModerateVenueAction::class)->approve($this->venue, $this->moderatore);

    Notification::assertSentTo($this->referente, VenueModerated::class);
});

it('avvisa il referente quando il locale viene rifiutato, col motivo', function (): void {
    app(ModerateVenueAction::class)->reject($this->venue, $this->moderatore, 'indirizzo inesistente');

    Notification::assertSentTo(
        $this->referente,
        VenueModerated::class,
        function (VenueModerated $avviso) use (&$corpo): bool {
            $corpo = $avviso->toMail($this->referente);

            /* Il motivo deve arrivare a destinazione, non restare nel
               pannello: e' la differenza fra un rifiuto a cui si puo'
               rimediare e uno a cui si puo' solo rispondere «perche'?». */
            return collect($corpo->introLines)->contains(
                fn (string $riga): bool => str_contains($riga, 'indirizzo inesistente')
            );
        }
    );
});

it('non offre il pannello a chi e stato sospeso', function (): void {
    $approvato = Venue::factory()->approved()->create();
    $approvato->members()->attach($this->referente, ['role' => 'owner']);

    app(ModerateVenueAction::class)->suspend($approvato, 'in verifica');

    Notification::assertSentTo($this->referente, VenueModerated::class, function (VenueModerated $avviso): bool {
        /* Un pulsante «apri il pannello» dentro l'email che comunica la
           sospensione porterebbe a una porta chiusa: `canAccessPanel` lo
           respinge. Offrire un collegamento che rifiuta e' peggio che non
           offrirne nessuno. */
        return $avviso->toMail($this->referente)->actionUrl === null;
    });
});

it('non manda niente se il locale non ha referenti', function (): void {
    $orfano = Venue::factory()->pending()->create();

    app(ModerateVenueAction::class)->approve($orfano, $this->moderatore);

    Notification::assertNothingSent();
});

it('usa il testo riscritto dal pannello al posto di quello del file', function (): void {
    NotificationText::updateOrCreate(
        ['key' => 'notifications.venue_approved.subject'],
        ['value' => 'Benvenuto a bordo, :venue!'],
    );

    /* Il traduttore ha gia' letto il file: senza ricostruirlo si leggerebbe
       la versione in memoria, e il test direbbe che la riscrittura non
       funziona quando invece e' il test a guardare troppo tardi. */
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');

    $avviso = new VenueModerated($this->venue, VenueStatus::Approved);

    expect($avviso->toMail($this->referente)->subject)
        ->toBe('Benvenuto a bordo, '.$this->venue->name.'!');
});

it('torna al testo del file quando la riscrittura viene cancellata', function (): void {
    NotificationText::updateOrCreate(
        ['key' => 'notifications.venue_approved.subject'],
        ['value' => 'Testo temporaneo'],
    );
    NotificationText::query()->delete();

    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');

    /* E' la ragione per cui la tabella contiene solo le differenze: cancellare
       una riga deve riportare all'originale, non lasciare un'email vuota. */
    expect((new VenueModerated($this->venue, VenueStatus::Approved))->toMail($this->referente)->subject)
        ->toContain($this->venue->name)
        ->not->toBe('Testo temporaneo');
});
