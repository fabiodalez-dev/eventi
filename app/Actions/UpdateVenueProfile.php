<?php

declare(strict_types=1);

namespace App\Actions;

use App\Filament\Admin\Support\StructuredFields;
use App\Models\Venue;
use App\Support\Description;
use App\Support\VenueClosures;

/**
 * Salva la scheda pubblica del locale: la pagina «Il tuo locale» del pannello
 * e `PATCH /management/venues/{venue}/profile` dell'app passano da qui, così
 * la regola di salvataggio è una sola.
 *
 * Riceve i campi nella forma del modulo e salva **solo quelli che riceve**:
 * il pannello li manda tutti, l'app una sezione alla volta. Quello che c'è da
 * tradurre al confine sta qui:
 *
 * - gli orari arrivano come righe piatte (giorno, dalle, alle) e si salvano
 *   come mappa per giorno (D19);
 * - le chiusure passano da `VenueClosures::normalize`, che le convalida,
 *   toglie quelle finite e le mette in ordine nel fuso della città;
 * - `content_details` e `seo` sono una colonna sola per più sezioni (prima di
 *   andare, informazioni editoriali): le chiavi ricevute sostituiscono quelle
 *   salvate, le altre restano;
 * - descrizione e note sulla tessera passano dallo stesso filtro dell'editor
 *   del pannello.
 *
 * Il resto (pulizia dell'HTML annidato, conferma del recapito del modulo di
 * contatto, coordinate sulla mappa, indicizzazione riservata alla redazione)
 * lo fa il modello quando salva, per qualunque strada arrivi la modifica.
 *
 * L'autorizzazione (`VenuePolicy::update`, solo il referente) la controlla
 * chi chiama.
 */
final class UpdateVenueProfile
{
    /** I campi della scheda che il locale scrive. Nome, tipo e stato li tiene la redazione. */
    public const FIELDS = [
        'short_description', 'description',
        'address', 'address_extra', 'postal_code', 'municipality', 'zone', 'lat', 'lng',
        'opening_hours', 'closures', 'capacity',
        'phone', 'email', 'website', 'socials',
        'transit', 'accessibility', 'info', 'requires_membership', 'membership_notes',
        'content_details', 'seo', 'contact_mode', 'contact_email',
    ];

    /** I campi a testo formattato della radice: lo stesso filtro di `DescriptionEditor`. */
    private const RICH = ['description', 'membership_notes'];

    /** Le colonne JSON divise fra più sezioni: si fondono invece di sostituirsi. */
    private const MERGED = ['content_details', 'seo'];

    /**
     * @param  array<string, mixed>  $state  i campi del modulo; quelli assenti restano come sono
     */
    public function handle(Venue $venue, array $state): Venue
    {
        $state = array_intersect_key($state, array_flip(self::FIELDS));

        if (array_key_exists('opening_hours', $state)) {
            $state['opening_hours'] = StructuredFields::openingHoursToMap($state['opening_hours']);
        }

        if (array_key_exists('closures', $state)) {
            $state['closures'] = VenueClosures::normalize(
                is_array($state['closures']) ? $state['closures'] : null,
                $venue->city->timezone ?? config()->string('app.timezone'),
            );
        }

        foreach (self::RICH as $field) {
            if (is_string($state[$field] ?? null)) {
                $state[$field] = Description::sanitize($state[$field]);
            }
        }

        foreach (self::MERGED as $field) {
            if (array_key_exists($field, $state) && is_array($state[$field])) {
                $saved = $venue->getAttribute($field);
                $state[$field] = array_replace(is_array($saved) ? $saved : [], $state[$field]);
            }
        }

        $venue->fill($state)->save();

        return $venue;
    }
}
