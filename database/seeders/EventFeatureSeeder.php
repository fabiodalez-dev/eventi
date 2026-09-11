<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EventFeature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EventFeatureSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'Accessibilità' => [
                ['Ingresso senza gradini', 'arrow-right-on-rectangle'], ['Bagno accessibile', 'hand-raised'], ['Posti riservati per persone con disabilità', 'heart'], ['Accompagnatore ammesso gratuitamente', 'user-group'], ['Assistenza su richiesta', 'heart'], ['Interprete LIS', 'chat-bubble-left-right'], ['Sottotitoli disponibili', 'chat-bubble-left-right'], ['Audiodescrizione', 'speaker-wave'], ['Percorso tattile', 'hand-raised'], ['Cani guida ammessi', 'shield-check'], ['Area tranquilla', 'sparkles'], ['Luci stroboscopiche presenti', 'eye'], ['Suoni ad alto volume', 'speaker-wave'],
            ],
            'Ingresso' => [
                ['Accesso in coppia', 'user-group'], ['Prenotazione obbligatoria', 'ticket'], ['Documento richiesto', 'identification'], ['Biglietto nominativo', 'ticket'], ['Ingresso fino a esaurimento posti', 'users'], ['Rientro consentito', 'arrow-right-on-rectangle'], ['Controlli di sicurezza', 'shield-check'], ['Arrivare in anticipo', 'clock'],
            ],
            'Pubblico' => [
                ['Minori accompagnati', 'users'], ['Adatto alle famiglie', 'users'], ['Riservato ai maggiorenni', 'identification'], ['Animali ammessi', 'heart'], ['Evento in italiano', 'chat-bubble-left-right'], ['Evento in inglese', 'globe-alt'],
            ],
            'Servizi' => [
                ['Guardaroba disponibile', 'shopping-bag'], ['Deposito passeggini', 'users'], ['Fasciatoio', 'users'], ['Acqua potabile disponibile', 'beaker'], ['Punti ristoro', 'cake'], ['Opzioni vegetariane', 'cake'], ['Opzioni vegane', 'cake'], ['Pagamenti elettronici', 'credit-card'], ['Solo contanti', 'currency-euro'], ['Wi-Fi disponibile', 'device-phone-mobile'], ['Ricarica telefono', 'device-phone-mobile'], ['Posti a sedere', 'users'],
            ],
            'Mobilità' => [['Raggiungibile con mezzi pubblici', 'truck'], ['Parcheggio biciclette', 'map-pin'], ['Navetta disponibile', 'truck'], ['Parcheggio riservato disabili', 'hand-raised']],
            'Regole' => [['All’aperto', 'sun'], ['Evento confermato con pioggia', 'cloud'], ['Foto consentite', 'camera'], ['Foto e video non consentiti', 'camera'], ['Borse grandi non ammesse', 'shopping-bag'], ['Abbigliamento richiesto', 'information-circle']],
        ];
        foreach ($groups as $group => $rows) {
            foreach ($rows as [$name, $icon]) {
                EventFeature::firstOrCreate(['slug' => Str::slug($name)], compact('name', 'icon', 'group'));
            }
        }
        foreach ([['membership-required', 'Tessera richiesta', 'identification', 'Ingresso'], ['membership-not_required', 'Tessera non richiesta', 'identification', 'Ingresso'], ['accessibility-yes', 'Accessibile in sedia a rotelle', 'hand-raised', 'Accessibilità'], ['accessibility-no', 'Non accessibile in sedia a rotelle', 'hand-raised', 'Accessibilità']] as [$slug, $name, $icon, $group]) {
            $feature = EventFeature::firstOrNew(['slug' => $slug]);
            if (! $feature->exists) {
                $feature->fill(compact('name', 'icon', 'group'));
                $feature->is_system = true;
                $feature->save();
            }
        }
    }
}
