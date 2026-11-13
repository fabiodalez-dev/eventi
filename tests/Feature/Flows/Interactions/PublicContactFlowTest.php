<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\ContactEmailConfirmationRequested;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * Il modulo «Contatta» di locali e organizzatori: parte solo verso un
 * recapito che il destinatario ha confermato (doppio opt-in) e nel modo che
 * ha scelto (spento, solo iscritti, tutti con captcha), con l'identità
 * dell'account e un tetto giornaliero per mittente.
 */
beforeEach(function (): void {
    Notification::fake();
    Mail::fake();
    $this->city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria dei Fiori', 'contact_mode' => ContactMode::Members]);
    $this->venuePage = route('venues.show', ['slug' => $this->venue->slug]);
    $this->webContact = route('public.contact', ['type' => 'venues', 'slug' => $this->venue->slug]);
});

/** Il collegamento di conferma spedito al recapito, preso dalla notifica. */
function intFlowConfirmationLink(string $email): string
{
    $url = '';
    Notification::assertSentOnDemand(ContactEmailConfirmationRequested::class, function (ContactEmailConfirmationRequested $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }
        $url = $notification->url;

        return true;
    });

    return $url;
}

it('il recapito del locale riceve messaggi solo dopo averlo confermato, con l\'identità dell\'account e un tetto per mittente', function (): void {
    $owner = User::factory()->create();
    $this->venue->members()->attach($owner, ['role' => 'owner']);
    $sender = User::factory()->create(['name' => 'Giorgio Rossi', 'email' => 'giorgio@example.test']);

    // Il referente imposta il recapito: parte la richiesta di conferma.
    $this->actingAs($owner);
    $this->venue->update(['contact_email' => 'prenotazioni@osteria.test']);
    $link = intFlowConfirmationLink('prenotazioni@osteria.test');
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();

    // Finché non è confermato il modulo non c'è e l'invio non parte.
    $this->actingAs($sender)->get($this->venuePage)->assertOk()->assertDontSee('id="contatta"', false);
    $this->post($this->webContact, ['message' => 'Vorrei prenotare un tavolo per sabato.'])->assertNotFound();
    Mail::assertNothingSent();

    // Il titolare della casella apre il link (che non conferma da solo) e preme il pulsante.
    $this->get($link)->assertOk()->assertSee(__('contact.confirmation.page_submit'));
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();
    $this->post($link)->assertOk();
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue();

    // Ora il modulo compare e il messaggio parte col nome dell'account, non con quello digitato.
    $this->get($this->venuePage)->assertOk()->assertSee('id="contatta"', false)->assertDontSee('prenotazioni@osteria.test');
    $this->post($this->webContact, ['name' => 'Nome finto', 'email' => 'finto@example.test', 'message' => 'Vorrei prenotare un tavolo per sabato.'])
        ->assertRedirect($this->venuePage.'#contatta')->assertSessionHas('status', __('contact.sent'));
    Mail::assertSent(PublicContact::class, fn (PublicContact $mail): bool => $mail->hasTo('prenotazioni@osteria.test')
        && $mail->senderName === 'Giorgio Rossi' && $mail->senderEmail === 'giorgio@example.test'
        && $mail->targetName === 'Osteria dei Fiori' && $mail->messageText === 'Vorrei prenotare un tavolo per sabato.');

    // Al quarto messaggio della giornata lo stesso mittente viene fermato, col testo che torna nel modulo.
    $this->post($this->webContact, ['message' => 'Secondo messaggio della giornata.'])->assertRedirect();
    $this->post($this->webContact, ['message' => 'Terzo messaggio della giornata.'])->assertRedirect();
    $this->from($this->venuePage)->post($this->webContact, ['message' => 'Quarto messaggio, uno di troppo.'])
        ->assertRedirect($this->venuePage)->assertSessionHasErrors('message');
    Mail::assertSentCount(3);
});

it('l\'app legge le impostazioni dell\'organizzatore e scrive solo da account confermato e finché il modulo è acceso', function (): void {
    $organizer = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Suoni in città', 'is_active' => true,
        'contact_mode' => ContactMode::Members, 'contact_email' => 'info@suoni.test']);
    $organizer->markContactEmailConfirmed();
    $api = '/api/v1/organizers/'.$organizer->slug.'/contact';

    // L'ospite vede che il modulo esiste ma non è per lui; il recapito resta privato.
    $this->getJson($api)->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.guests', false)
        ->assertJsonPath('data.url', route('organizers.show', ['slug' => $organizer->slug]).'#contatta')
        ->assertDontSee('info@suoni.test');
    $this->postJson($api, ['name' => 'Ospite', 'email' => 'ospite@example.test', 'message' => 'Siete disponibili per un evento privato?'])->assertForbidden();

    // Un account non confermato non può firmare il messaggio con la sua email.
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson($api, ['message' => 'Siete disponibili per un evento privato?'])
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

    $giulia = User::factory()->create(['name' => 'Giulia Neri', 'email' => 'giulia@example.test']);
    Sanctum::actingAs($giulia);
    $this->postJson($api, ['message' => 'corto'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['message']]]);
    $this->postJson($api, ['message' => 'Siete disponibili per un evento privato?'])->assertOk()->assertJsonPath('data.message', __('contact.sent'));
    Mail::assertSent(PublicContact::class, fn (PublicContact $mail): bool => $mail->hasTo('info@suoni.test') && $mail->senderEmail === 'giulia@example.test');

    // L'organizzatore spegne il modulo: niente più messaggi.
    $organizer->update(['contact_mode' => ContactMode::Disabled]);
    $this->getJson($api)->assertJsonPath('data.enabled', false);
    $this->postJson($api, ['message' => 'Riprovo dopo lo spegnimento.'])->assertNotFound();

    // Un organizzatore disattivato non esiste per il pubblico.
    $organizer->update(['is_active' => false, 'contact_mode' => ContactMode::Members]);
    $this->getJson($api)->assertNotFound();
    Mail::assertSentCount(1);
});

it('un locale aperto a tutti riceve dagli ospiti solo dopo il captcha verificato, e smette se torna ai soli iscritti', function (): void {
    config(['contact.recaptcha_site_key' => 'chiave-sito', 'contact.recaptcha_secret_key' => 'chiave-segreta']);
    Http::fake(['https://www.google.com/recaptcha/*' => Http::sequence()
        ->push(['success' => true, 'hostname' => config('contact.recaptcha_hostname')])
        ->push(['success' => false, 'hostname' => config('contact.recaptcha_hostname')])]);
    $this->venue->update(['contact_mode' => ContactMode::Everyone, 'contact_email' => 'cassa@osteria.test']);
    $this->venue->markContactEmailConfirmed();
    $api = '/api/v1/venues/'.$this->venue->slug.'/contact';

    $this->getJson($api)->assertJsonPath('data.enabled', true)->assertJsonPath('data.guests', true);
    $this->get($this->venuePage)->assertOk()->assertSee('id="contatta"', false)->assertSee('data-sitekey="chiave-sito"', false);

    $this->post($this->webContact, ['name' => 'Anna Ospite', 'email' => 'anna@example.test', 'message' => 'Avete un menù vegetariano?', 'g-recaptcha-response' => 'token-valido'])
        ->assertRedirect($this->venuePage.'#contatta')->assertSessionHas('status', __('contact.sent'));
    Mail::assertSent(PublicContact::class, fn (PublicContact $mail): bool => $mail->hasTo('cassa@osteria.test')
        && $mail->senderName === 'Anna Ospite' && $mail->senderEmail === 'anna@example.test');
    Http::assertSent(fn (HttpRequest $request): bool => $request['secret'] === 'chiave-segreta' && $request['response'] === 'token-valido');

    // Captcha respinto: errore sul campo, nessuna mail.
    $this->from($this->venuePage)->post($this->webContact, ['name' => 'Anna Ospite', 'email' => 'anna@example.test', 'message' => 'Seconda domanda per voi.', 'g-recaptcha-response' => 'token-falso'])
        ->assertRedirect($this->venuePage)->assertSessionHasErrors('g-recaptcha-response');

    // Un a capo nel nome (iniezione di intestazioni) non passa la validazione.
    $this->post($this->webContact, ['name' => "Anna\r\nBcc: tutti@example.test", 'email' => 'anna@example.test', 'message' => 'Terza domanda per voi.'])
        ->assertSessionHasErrors('name');

    // Il locale torna ai soli iscritti: l'ospite non scrive più.
    $this->venue->update(['contact_mode' => ContactMode::Members]);
    $this->getJson($api)->assertJsonPath('data.guests', false);
    $this->post($this->webContact, ['name' => 'Anna Ospite', 'email' => 'anna@example.test', 'message' => 'Quarta domanda per voi.', 'g-recaptcha-response' => 'token-valido'])
        ->assertForbidden();

    Mail::assertSentCount(1);
    Http::assertSentCount(2);
});
