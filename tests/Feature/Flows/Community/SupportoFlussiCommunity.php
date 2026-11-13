<?php

declare(strict_types=1);

/*
 * Aiuti condivisi dai test di flusso della community.
 *
 * Il file non finisce in `Test.php` di proposito: Pest non lo esegue come
 * test, lo caricano con `require_once` i file della cartella. I nomi hanno il
 * prefisso `fcom` perché le funzioni dichiarate nei file di Pest sono globali
 * per tutta la suite, e `communityPerson()`, `pollDates()` e simili esistono
 * già altrove.
 */

use App\Actions\Account\SaveOccurrences;
use App\Models\Category;
use App\Models\City;
use App\Models\CommunityPost;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Community\Community;
use App\Services\Community\WhatsappVerification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/** Community accesa, verifica WhatsApp configurata e Kapso finto che accetta ogni invio. */
function fcomPrepara(): void
{
    config([
        'community.enabled' => true,
        'community.whatsapp_enabled' => true,
        'community.kapso_key' => 'chiave-di-prova',
        'community.phone_number_id' => '123',
        'community.phone_hash_key' => 'impronta-di-prova',
        'community.handle_change_cooldown_days' => 30,
    ]);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.prova']]])]);
}

/**
 * Un account con email confermata e numero WhatsApp verificato, senza profilo.
 *
 * @param  array<string, mixed>  $attributi
 */
function fcomVerificato(array $attributi = []): User
{
    $user = User::factory()->create($attributi);
    $numero = '+39340'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT);
    $user->forceFill([
        'whatsapp_phone' => $numero,
        'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint($numero),
        'whatsapp_verified_at' => now(),
    ])->save();

    return $user->fresh();
}

/** Una persona della community: numero verificato e profilo con la visibilità indicata. */
function fcomPersona(string $handle, string $visibilita = 'public', ?string $nome = null): User
{
    $user = fcomVerificato();
    $user->communityProfile()->create([
        'handle' => $handle,
        'display_name' => $nome ?? ucfirst(str_replace('_', ' ', $handle)),
        'visibility' => $visibilita,
    ]);

    return $user->fresh();
}

/**
 * Una data pubblicata, con l'orario scritto nell'ora locale della città.
 *
 * @param  array<string, mixed>  $evento
 */
function fcomData(City $city, Category $category, string $inizioLocale, array $evento = []): EventOccurrence
{
    $fine = localInstant($city, $inizioLocale)->addHours(3)->format('Y-m-d H:i');

    return occurrenceAtLocal($city, $category, $inizioLocale, $fine, [], $evento);
}

/** Salva la data e ne pubblica il consiglio: la preparazione dei flussi che partono da un post. */
function fcomConsiglio(User $autore, EventOccurrence $data, string $testo): CommunityPost
{
    app(SaveOccurrences::class)->one($autore, $data);

    return app(Community::class)->publication($autore, $data->id, ['visibility' => 'public', 'body' => $testo, 'intent' => 'recommend']);
}

/** Il codice a sei cifre dell'ultimo messaggio passato dal Kapso finto. */
function fcomCodiceInviato(): string
{
    $ultimo = Http::recorded()->last();

    return (string) $ultimo[0]['template']['components'][0]['parameters'][0]['text'];
}

/** Torna ospite: dimentica chi era collegato, sul sito e nell'API. */
function fcomEsci(): void
{
    Auth::forgetGuards();
}
