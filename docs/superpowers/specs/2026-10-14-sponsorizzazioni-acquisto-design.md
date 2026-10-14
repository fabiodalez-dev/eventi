# Acquisto delle sponsorizzazioni da locali e organizzatori

14 ottobre 2026. Progettazione per l'acquisto in autonomia di un periodo di sponsorizzazione dal pannello del locale (`/gestione`) e dell'organizzatore (`/organizza`), con pagamento online tramite Stripe Checkout, approvazione della redazione e campagne visibili sul sito.

## Obiettivo

Oggi una sponsorizzazione nasce solo dalla redazione: un amministratore crea un'abilitazione (`sponsorship_grants`) per un locale, registra a mano il pagamento ricevuto, e il locale sceglie gli eventi dal pannello. Gli organizzatori non possono sponsorizzare nulla. Il flusso nuovo:

1. Il locale o l'organizzatore sceglie dal listino una posizione e una durata, e la modalità (tutti gli eventi, o eventi scelti).
2. Paga con carta su Stripe Checkout.
3. La redazione vede l'ordine pagato e lo approva o lo rifiuta (con rimborso).
4. All'approvazione nasce l'abilitazione, già pagata, con il periodo che parte dall'approvazione.
5. Le campagne compaiono sul sito con le regole di oggi (`Sponsorship::visible()`, selettore, tetti per posizione, etichetta «Sponsorizzato» con il nome del committente).

Nulla di quello che esiste cambia per la redazione: le abilitazioni manuali e gratuite restano come sono.

## Decisioni

- **Pagamento con Stripe Checkout**, pagina ospitata da Stripe. Il sito non vede mai i dati della carta. L'ordine si considera pagato solo al webhook `checkout.session.completed` (o `async_payment_succeeded`) con firma verificata, oppure al ritorno dal checkout se Stripe conferma `payment_status = paid` interrogando la sessione: le due vie sono idempotenti.
- **Il pagamento non basta: serve l'approvazione.** Resta vero che «chi decide non è chi modera» (D53): un ordine pagato è una richiesta, e la redazione decide. Il periodo parte dall'approvazione, non dal pagamento, così chi paga di venerdì sera non perde il fine settimana.
- **Rifiuto = rimborso.** Rifiutare un ordine pagato chiama il rimborso su Stripe e registra la motivazione. Il rimborso fallito lascia l'ordine rifiutato con la nota dell'errore e va sistemato dalla dashboard Stripe: non blocca la decisione.
- **Listino in tabella**, gestito dall'admin: posizione, mesi, prezzo. Il prezzo dell'ordine è una copia al momento dell'acquisto.
- **Gli organizzatori sono committenti come i locali.** `sponsorship_grants` accetta un locale oppure un organizzatore, mai entrambi, mai nessuno. Un'abilitazione da organizzatore copre gli eventi con quell'`organizer_id`; attiva solo se l'organizzatore è attivo.
- **Pacchetto**: `stripe/stripe-php` ^22 (installazione provata a secco il 14/10). Nessun Cashier: serve un checkout per ordine singolo, non abbonamenti.

## Schema

### `sponsorship_prices` (nuova)

| colonna | tipo | note |
|---|---|---|
| id | bigint | |
| placement | string | `SponsorshipPlacement` |
| months | unsigned tinyint | 1, 2, 3, 6, 12 |
| amount_cents | unsigned int | |
| currency | string(3) | `EUR` |
| is_active | boolean | default true |
| timestamps | | |

Unico su `(placement, months)`. Seed con prezzi segnaposto (da correggere dalla redazione).

### `sponsorship_orders` (nuova)

| colonna | tipo | note |
|---|---|---|
| id | bigint | |
| venue_id | FK nullable | restrict |
| organizer_id | FK nullable | restrict |
| user_id | FK | chi ha ordinato, nullOnDelete |
| sponsorship_price_id | FK nullable | nullOnDelete |
| placement | string | copia |
| mode | string | `PromotionMode` |
| months | unsigned tinyint | copia |
| amount_cents | unsigned int | copia |
| currency | string(3) | |
| status | string | `SponsorshipOrderStatus` |
| checkout_session_id | string nullable | unico |
| payment_intent_id | string nullable | |
| paid_at | datetime nullable | |
| decided_at | datetime nullable | |
| decided_by | FK users nullable | |
| decision_note | text nullable | motivazione del rifiuto o esito del rimborso |
| sponsorship_grant_id | FK nullable | l'abilitazione nata dall'approvazione |
| timestamps | | |

Indice su `status`. Esattamente uno fra `venue_id` e `organizer_id` (controllato dal modello e dal servizio).

### `sponsorship_grants` (modifica)

`venue_id` diventa nullable; si aggiunge `organizer_id` FK nullable (restrict). Indice su `organizer_id`.

## Enum

`SponsorshipOrderStatus`: `pending_payment`, `paid`, `approved`, `rejected`, `cancelled`. Transizioni: `pending_payment → paid | cancelled`; `paid → approved | rejected`. Tutto il resto è rifiutato con eccezione.

## Codice

### Committente

`App\Models\SponsorshipGrant` e `App\Models\SponsorshipOrder` espongono `sponsor(): Venue|Organizer` e `sponsorName()`. Il grant ha `coversEvent(Event $event): bool` (stesso `venue_id` oppure stesso `organizer_id`) e `eventsQuery()` (gli eventi coperti), usati da `GrantCampaigns` al posto dei confronti diretti su `venue_id`.

`SponsorshipGrant::scopeActive()`: la condizione «locale approvato» diventa «locale approvato oppure organizzatore attivo» secondo il committente.

`Sponsorship::scopeVisible()`: la condizione sul locale del grant diventa «l'evento ha il `venue_id` del grant, oppure l'`organizer_id` del grant».

### Pagamento

`App\Services\Sponsorship\Payment\PaymentGateway` (interfaccia):

- `checkout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): CheckoutSession` → DTO `{id, url}`
- `session(string $id): CheckoutSession` → DTO con `paymentStatus` e `paymentIntentId`
- `refund(string $paymentIntentId): void`

`StripeGateway` implementa con `stripe/stripe-php`. `FakePaymentGateway` (in `tests/Support`) registra le chiamate e restituisce URL finti. Il binding sta in `AppServiceProvider`; i test lo sostituiscono.

`App\Services\Sponsorship\Payment\StripeWebhookSignature`: verifica la firma con `Stripe\Webhook::constructEvent` e il segreto in `services.stripe.webhook_secret`. Non è dietro l'interfaccia: la verifica è locale e i test la esercitano davvero, firmando il payload con lo stesso segreto.

### Ordini

`App\Services\Sponsorship\SponsorshipOrders`:

- `place(User $buyer, Venue|Organizer $sponsor, SponsorshipPrice $price, PromotionMode $mode, string $successUrl, string $cancelUrl): SponsorshipOrder` — verifica che il prezzo sia attivo e che il committente possa sponsorizzare (locale approvato / organizzatore attivo), crea l'ordine `pending_payment`, apre la sessione di checkout e salva l'id. L'URL del checkout è su `$order->checkout_url` (attributo non persistito).
- `markPaid(SponsorshipOrder $order, string $paymentIntentId, CarbonImmutable $paidAt): void` — idempotente: se già pagato o deciso non fa nulla. Notifica gli amministratori con `sponsorships.manage`.
- `approve(SponsorshipOrder $order, User $admin): SponsorshipGrant` — in transazione con lock: crea il grant (`starts_at = now`, `ends_at = starts + mesi` in Europe/Rome senza overflow, `paid_at`, `amount_cents`, `payment_method = 'stripe'`, `payment_reference = payment_intent_id`, `created_by = admin`), collega l'ordine, se automatico sincronizza subito le campagne. Notifica chi ha ordinato.
- `reject(SponsorshipOrder $order, User $admin, string $reason): void` — rimborso via gateway, stato `rejected`, nota. Se il rimborso lancia, la nota riporta l'errore e l'ordine resta rifiutato. Notifica chi ha ordinato.
- `cancel(SponsorshipOrder $order): void` — solo da `pending_payment`.
- `reconcile(SponsorshipOrder $order): void` — al ritorno dal checkout: legge la sessione dal gateway e, se pagata, chiama `markPaid`.

### HTTP

- `POST /webhooks/stripe` → `StripeWebhookController`: verifica firma (400 se invalida), cerca l'ordine per `checkout_session_id`, chiama `markPaid`. Risponde 200 anche per eventi ignoti o ordini non trovati (Stripe non deve ritentare). Escluso dal CSRF.
- Ritorno dal checkout: le pagine dei pannelli accettano `?order=ID&session_id=...` in `mount()`, verificano che l'ordine sia del tenant e chiamano `reconcile`.

### Pannelli

- `App\Filament\Shared\SponsorshipsPage` (classe astratta, estende `Page`): contiene tutto ciò che oggi sta in `Venue\Pages\Sponsorships` più acquisto, elenco ordini e annullamento. Le sottoclassi dichiarano `sponsor(): Venue|Organizer` e `successUrl()/cancelUrl()`. `Venue\Pages\Sponsorships` e `Organizer\Pages\Sponsorships` diventano due classi corte.
- Azione «Acquista una sponsorizzazione»: select del listino (etichetta «Posizione · N mesi · prezzo»), select modalità; conferma → `place()` → `redirect()->away(checkout_url)`.
- Elenco ordini nel pannello: data, posizione, durata, importo, stato; «Annulla» su quelli in attesa di pagamento; «Riprova il pagamento» riapre il checkout.
- Admin `SponsorshipOrderResource` (gruppo Promozione): tabella con committente, posizione, durata, importo, stato, pagato il; filtri per stato; azioni «Approva» e «Rifiuta» (motivazione obbligatoria) visibili solo su `paid`; nessuna creazione manuale.
- Admin `SponsorshipPriceResource` («Listino sponsorizzazioni», gruppo Promozione): CRUD semplice.
- `SponsorshipGrantResource`: il campo «Locale» diventa una coppia «Locale» / «Organizzatore» con validazione «uno dei due».
- Pannello organizzatore: registra la pagina e aggiunge il gruppo.

### Autorizzazioni

- `SponsorshipOrderPolicy`: `viewAny/approve/reject` ad admin e super admin (come i grant). Chi ordina non passa dalla policy admin: il pannello limita al tenant corrente, e il servizio verifica che il tenant corrisponda.
- `SponsorshipPricePolicy`: admin e super admin.

### Notifiche

- `SponsorshipOrderPaid` (mail agli amministratori): committente, importo, collegamento all'ordine in `/admin`.
- `SponsorshipOrderDecided` (mail a chi ha ordinato): approvato con il periodo, oppure rifiutato con la motivazione e l'avviso del rimborso.

### Configurazione

`config/services.php`: `stripe.key`, `stripe.secret`, `stripe.webhook_secret`. `.env.example` con le tre variabili vuote. Senza `secret` il pulsante di acquisto non compare e il servizio rifiuta `place()` con un messaggio chiaro.

## Stringhe

Tutte in `lang/it/promotions.php` (ordini, listino, azioni, stati, notifiche). Nessuna stringa nel codice.

## Verifica

`tests/Feature/Sponsorships/SponsorshipPurchaseFlowTest.php`, il percorso intero:

1. Il referente del locale apre il pannello, acquista (listino + modalità scelti) → ordine `pending_payment`, sessione aperta sul gateway finto, redirect all'URL del checkout.
2. Arriva il webhook firmato con il segreto di prova → `paid`, `payment_intent_id` salvato, mail agli amministratori. Un secondo invio identico non cambia nulla. Un webhook senza firma valida risponde 400 e non tocca l'ordine.
3. L'amministratore approva dalla risorsa → nasce il grant pagato con il periodo giusto, ordine `approved`, mail a chi ha ordinato.
4. Il referente sceglie l'evento → `GET /eventi` mostra «Sponsorizzato», il nome del locale e il titolo dell'evento.
5. Lo stesso per un organizzatore in modalità automatica: dopo l'approvazione, l'evento dell'organizzatore compare in `/eventi` senza scegliere nulla.
6. Rifiuto: rimborso chiamato sul gateway, nessun grant, niente in `/eventi`, mail con la motivazione.
7. Isolamento: il referente del locale B non vede né annulla l'ordine di A; chi ordina non può approvare; il ritorno dal checkout con un ordine altrui non riconcilia nulla.
8. Senza chiavi Stripe, l'acquisto non è offerto.

`tests/Browser/SponsorshipPurchaseTest.php`: dal pannello del locale, a 390 e 1440 px, si apre l'azione, si sceglie dal listino, si conferma e si viene mandati all'URL del checkout finto.

Suite completa, PHPStan livello 6, Pint, Deptrac come per ogni modifica.

## Fuori ambito

Abbonamenti, pagamento a click, fatturazione elettronica, coupon. La fattura la emette Stripe (ricevuta automatica) o la redazione a mano, come oggi.
