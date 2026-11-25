<?php

declare(strict_types=1);

namespace App\Support\VenueApplications;

use App\Enums\VenueType;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone as PhoneRule;

/**
 * Le regole dei campi di «Registra il tuo locale», della rivendicazione e di
 * «Registra la tua organizzazione», scritte una volta sola: le usano i moduli
 * del sito (`StoreVenueApplicationRequest`, `StoreOrganizerApplicationRequest`)
 * e l'app (`Api\V1\Management\ApplicationRequest`).
 *
 * Qui ci sono solo i campi della richiesta. Quello che serve a un ospite
 * (email, password, tempo minimo, campo esca, Turnstile) lo aggiunge il modulo
 * del sito: nell'app si scrive sempre da collegati, con l'email confermata.
 */
final class ApplicationRules
{
    /** I dati pubblici del locale che finiscono in `venue_applications.venue_data`. */
    private const VENUE_DATA_FIELDS = ['postal_code', 'municipality', 'public_email', 'public_phone', 'website', 'instagram', 'facebook', 'description'];

    /**
     * Un locale nuovo: la scheda pubblica, l'immagine e il messaggio.
     *
     * @return array<string, list<mixed>>
     */
    public static function venue(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(VenueType::values())],
            'address' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'public_email' => ['nullable', 'email:filter', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:40', self::phone()],
            'instagram' => ['nullable', 'url:http,https', 'max:255'],
            'facebook' => ['nullable', 'url:http,https', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=400,min_height=300,max_width=8000,max_height=8000'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * La rivendicazione: il locale c'è già, si chiede solo come verificarla.
     *
     * @return array<string, list<mixed>>
     */
    public static function claim(): array
    {
        return [
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * Un'organizzazione: come un locale, senza tipo e senza indirizzo.
     *
     * @return array<string, list<mixed>>
     */
    public static function organizer(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Il referente, uguale nei tre moduli.
     *
     * @return array<string, list<mixed>>
     */
    public static function contact(): array
    {
        return [
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            /*
             * Il telefono si valida davvero, non solo per lunghezza.
             *
             * `max:40` accettava qualunque cosa: «chiamami», un indirizzo
             * email, quaranta lettere. E chi modera scopre che il numero non
             * serve a niente nel momento in cui prova a usarlo — cioè quando
             * ha bisogno di chiarire qualcosa su una richiesta.
             *
             * `IT` come regione implicita perché il modulo è italiano e
             * nessuno scrive il prefisso internazionale del proprio Paese;
             * `INTERNATIONAL` per non respingere un numero estero scritto per
             * intero, che su un evento di confine capita.
             */
            'contact_phone' => ['nullable', 'string', 'max:40', self::phone()],
        ];
    }

    /**
     * I dati pubblici del locale, per `venue_applications.venue_data`, presi
     * dai campi già validati.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|int>
     */
    public static function venueData(array $validated): array
    {
        $data = [];
        foreach (self::VENUE_DATA_FIELDS as $key) {
            $value = $validated[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $data[$key] = trim($value);
            }
        }
        $capacity = $validated['capacity'] ?? null;
        if (is_numeric($capacity)) {
            $data['capacity'] = (int) $capacity;
        }

        return $data;
    }

    private static function phone(): PhoneRule
    {
        return (new PhoneRule)->country('IT')->type(['mobile', 'fixed_line'])->international();
    }
}
