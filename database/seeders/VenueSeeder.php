<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\VenuePlan;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\City;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * 25 locali della provincia di Padova (D11) con coordinate reali dei comuni e
 * delle zone indicate: centro storico di Padova, Abano Terme, Selvazzano,
 * Albignasego, Vigonza, Cittadella, Este, Monselice, Piove di Sacco.
 *
 * 23 approvati, 1 in attesa (coda di moderazione), 1 in bozza; almeno 3
 * no-profit (§20.4 / D9: `is_nonprofit` gratuito per sempre).
 */
class VenueSeeder extends Seeder
{
    /**
     * @var list<array{
     *     name: string, type: string, municipality: string, postal_code: string,
     *     address: string, lat: float, lng: float, status: string,
     *     nonprofit: bool, capacity: int, description: string
     * }>
     */
    private const VENUES = [
        ['name' => 'Teatro Verdi', 'type' => 'teatro', 'municipality' => 'Padova', 'postal_code' => '35139', 'address' => 'Via dei Livello, 32', 'lat' => 45.4083, 'lng' => 11.8809, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 800, 'description' => 'Il principale teatro storico di Padova, palcoscenico di prosa, danza e grandi concerti.'],
        ['name' => 'Gran Teatro Geox', 'type' => 'teatro', 'municipality' => 'Padova', 'postal_code' => '35131', 'address' => 'Via Niccolò Tommaseo, 65', 'lat' => 45.4127, 'lng' => 11.8567, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 2000, 'description' => 'Arena coperta per i grandi eventi musicali e gli show di respiro nazionale.'],
        ['name' => 'Caffè Pedrocchi', 'type' => 'bar', 'municipality' => 'Padova', 'postal_code' => '35122', 'address' => 'Via VIII Febbraio, 15', 'lat' => 45.4070, 'lng' => 11.8765, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 150, 'description' => 'Storico caffè ottocentesco nel cuore di Padova, sale per reading e piccoli concerti.'],
        ['name' => "Caffè dell'Orto Botanico", 'type' => 'bar', 'municipality' => 'Padova', 'postal_code' => '35123', 'address' => 'Via Orto Botanico, 15', 'lat' => 45.3978, 'lng' => 11.8823, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 60, 'description' => 'Caffetteria affacciata sull\'Orto Botanico, aperitivi con musica dal vivo.'],
        ['name' => 'Hall Club Padova', 'type' => 'club', 'municipality' => 'Padova', 'postal_code' => '35138', 'address' => 'Riviera Tiso da Camposampiero, 3', 'lat' => 45.4147, 'lng' => 11.8656, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 500, 'description' => 'Club sul Naviglio, serate DJ set ed elettronica fino a tarda notte.'],
        ['name' => 'Extra Extra Club', 'type' => 'club', 'municipality' => 'Padova', 'postal_code' => '35142', 'address' => 'Via Facciolati, 84', 'lat' => 45.3925, 'lng' => 11.8898, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 350, 'description' => 'Club indipendente per la scena elettronica e underground padovana.'],
        ['name' => 'Circolo Arci La Fornace', 'type' => 'circolo', 'municipality' => 'Padova', 'postal_code' => '35135', 'address' => 'Via Fornace Morandi, 4', 'lat' => 45.4247, 'lng' => 11.8722, 'status' => 'approved', 'nonprofit' => true, 'capacity' => 200, 'description' => 'Circolo Arci di quartiere: musica dal vivo, cene sociali e sportello di quartiere.'],
        ['name' => 'Centro Sociale Pedro', 'type' => 'centro_sociale', 'municipality' => 'Padova', 'postal_code' => '35128', 'address' => 'Via Ognissanti, 90', 'lat' => 45.3841, 'lng' => 11.8592, 'status' => 'approved', 'nonprofit' => true, 'capacity' => 400, 'description' => 'Spazio sociale autogestito: concerti, assemblee, laboratori e sportelli popolari.'],
        ['name' => 'Libreria Feltrinelli Padova', 'type' => 'libreria', 'municipality' => 'Padova', 'postal_code' => '35121', 'address' => 'Via San Francesco, 7', 'lat' => 45.4048, 'lng' => 11.8783, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 80, 'description' => 'Libreria del centro con calendario fitto di presentazioni e incontri con autori.'],
        ['name' => 'Libreria La Toletta', 'type' => 'libreria', 'municipality' => 'Padova', 'postal_code' => '35122', 'address' => 'Via San Martino e Solferino, 9', 'lat' => 45.4062, 'lng' => 11.8709, 'status' => 'pending', 'nonprofit' => false, 'capacity' => 40, 'description' => 'Piccola libreria indipendente, appena candidata sulla piattaforma.'],
        ['name' => 'Galleria Cavour', 'type' => 'galleria', 'municipality' => 'Padova', 'postal_code' => '35121', 'address' => 'Piazza Cavour, 1', 'lat' => 45.4057, 'lng' => 11.8776, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 120, 'description' => 'Galleria espositiva nel centro storico, mostre fotografiche e arte contemporanea.'],
        ['name' => 'Osteria dei Fabbri', 'type' => 'ristorante', 'municipality' => 'Padova', 'postal_code' => '35139', 'address' => 'Via dei Fabbri, 13', 'lat' => 45.4074, 'lng' => 11.8756, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 70, 'description' => 'Osteria tradizionale padovana con serate a tema enogastronomico.'],
        ['name' => 'Pub Guinness Corner', 'type' => 'pub', 'municipality' => 'Padova', 'postal_code' => '35122', 'address' => 'Via Cesare Battisti, 22', 'lat' => 45.4093, 'lng' => 11.8814, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 90, 'description' => 'Pub irlandese storico, quiz night e concerti acustici infrasettimanali.'],
        ['name' => 'Associazione Culturale Sagunto', 'type' => 'associazione', 'municipality' => 'Padova', 'postal_code' => '35134', 'address' => 'Via Sagunto, 5', 'lat' => 45.4302, 'lng' => 11.8583, 'status' => 'approved', 'nonprofit' => true, 'capacity' => 100, 'description' => 'Associazione culturale di quartiere: corsi, laboratori e cinema popolare.'],
        ['name' => 'Cinema Lux Padova', 'type' => 'cinema', 'municipality' => 'Padova', 'postal_code' => '35124', 'address' => 'Via Nino Bixio, 16', 'lat' => 45.4118, 'lng' => 11.8843, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 300, 'description' => 'Multisala d\'essai, rassegne di cinema d\'autore e cineforum.'],
        ['name' => 'Termarium Abano', 'type' => 'spazio_pubblico', 'municipality' => 'Abano Terme', 'postal_code' => '35031', 'address' => 'Via Marzia, 3', 'lat' => 45.3600, 'lng' => 11.7905, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 250, 'description' => 'Parco termale con anfiteatro all\'aperto per eventi estivi e rassegne musicali.'],
        ['name' => 'Circolo Arci Selvazzano', 'type' => 'circolo', 'municipality' => 'Selvazzano Dentro', 'postal_code' => '35030', 'address' => 'Via Roma, 22', 'lat' => 45.3861, 'lng' => 11.7920, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 150, 'description' => 'Circolo di paese con sala polivalente per feste, tornei e proiezioni.'],
        ['name' => 'Bar Centrale Albignasego', 'type' => 'bar', 'municipality' => 'Albignasego', 'postal_code' => '35020', 'address' => 'Piazza Insurrezione, 4', 'lat' => 45.3690, 'lng' => 11.8700, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 50, 'description' => 'Bar della piazza principale, punto di ritrovo per aperitivi e piccoli eventi.'],
        ['name' => 'Teatro Sociale di Cittadella', 'type' => 'teatro', 'municipality' => 'Cittadella', 'postal_code' => '35013', 'address' => 'Via Marconi, 2', 'lat' => 45.6482, 'lng' => 11.7857, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 350, 'description' => 'Teatro storico dentro le mura medievali di Cittadella.'],
        ['name' => 'Osteria delle Mura', 'type' => 'ristorante', 'municipality' => 'Cittadella', 'postal_code' => '35013', 'address' => 'Via Borgo Padova, 10', 'lat' => 45.6470, 'lng' => 11.7860, 'status' => 'draft', 'nonprofit' => false, 'capacity' => 45, 'description' => 'Osteria appena aperta dentro le mura, profilo ancora in bozza.'],
        ['name' => 'Circolo Noi Este', 'type' => 'circolo', 'municipality' => 'Este', 'postal_code' => '35042', 'address' => "Via Massimo D'Azeglio, 6", 'lat' => 45.2320, 'lng' => 11.6600, 'status' => 'approved', 'nonprofit' => true, 'capacity' => 180, 'description' => 'Circolo parrocchiale con sala eventi per la comunità di Este.'],
        ['name' => 'Rocca di Monselice - Spazio Eventi', 'type' => 'spazio_pubblico', 'municipality' => 'Monselice', 'postal_code' => '35043', 'address' => 'Via del Santuario, 12', 'lat' => 45.2385, 'lng' => 11.7570, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 300, 'description' => 'Area verde ai piedi della Rocca, sede di sagre e mercatini stagionali.'],
        ['name' => 'Auditorium Vittorio Bachelet', 'type' => 'teatro', 'municipality' => 'Piove di Sacco', 'postal_code' => '35028', 'address' => 'Via Caduti del Lavoro, 5', 'lat' => 45.2985, 'lng' => 12.0312, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 400, 'description' => 'Auditorium comunale per teatro, danza e presentazioni.'],
        ['name' => 'Pub Old Charlie', 'type' => 'pub', 'municipality' => 'Vigonza', 'postal_code' => '35010', 'address' => 'Via Roma, 45', 'lat' => 45.4520, 'lng' => 11.9500, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 100, 'description' => 'Pub di paese con musica live nel weekend.'],
        ['name' => 'Ristorante Al Ponte', 'type' => 'ristorante', 'municipality' => 'Noventa Padovana', 'postal_code' => '35027', 'address' => 'Via Fiume, 2', 'lat' => 45.4210, 'lng' => 11.9370, 'status' => 'approved', 'nonprofit' => false, 'capacity' => 90, 'description' => 'Ristorante sul Brenta con serate a tema e degustazioni.'],
    ];

    public function run(): void
    {
        $city = City::where('slug', 'padova')->firstOrFail();

        foreach (self::VENUES as $data) {
            $status = VenueStatus::from($data['status']);

            Venue::create([
                'city_id' => $city->getKey(),
                'name' => $data['name'],
                'type' => VenueType::from($data['type']),
                'description' => $data['description'],
                'short_description' => $data['description'],
                'address' => $data['address'],
                'address_extra' => null,
                'postal_code' => $data['postal_code'],
                'municipality' => $data['municipality'],
                'province_code' => 'PD',
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                // Il costruttore di Point vuole (latitudine, longitudine) ma
                // scrive POINT(lng lat) con SRID 0, secondo la convenzione di §4.
                'location' => new Point($data['lat'], $data['lng'], 0),
                'phone' => '+39 049 '.random_int(100000, 999999),
                'email' => 'info@'.str($data['name'])->slug()->value().'.it',
                'website' => 'https://www.'.str($data['name'])->slug()->value().'.it',
                'socials' => ['instagram' => 'https://instagram.com/'.str($data['name'])->slug()->value()],
                'opening_hours' => $data['type'] === 'galleria' || $data['type'] === 'libreria'
                    ? [
                        'tue' => [['open' => '10:00', 'close' => '13:00'], ['open' => '15:30', 'close' => '19:30']],
                        'wed' => [['open' => '10:00', 'close' => '13:00'], ['open' => '15:30', 'close' => '19:30']],
                        'thu' => [['open' => '10:00', 'close' => '13:00'], ['open' => '15:30', 'close' => '19:30']],
                        'fri' => [['open' => '10:00', 'close' => '13:00'], ['open' => '15:30', 'close' => '19:30']],
                        'sat' => [['open' => '10:00', 'close' => '19:30']],
                        'sun' => [['open' => '10:00', 'close' => '13:00']],
                    ]
                    : null,
                'capacity' => $data['capacity'],
                'accessibility' => ['wheelchair' => random_int(0, 1) === 1],
                'requires_membership' => $data['nonprofit'],
                'membership_notes' => $data['nonprofit'] ? 'Ingresso riservato ai soci, tesseramento in sede.' : null,
                'status' => $status,
                'is_verified' => $status === VenueStatus::Approved,
                'is_nonprofit' => $data['nonprofit'],
                'plan' => VenuePlan::Free,
                'auto_publish' => false,
                'approved_at' => $status === VenueStatus::Approved ? now()->subDays(random_int(5, 200)) : null,
                'approved_by' => null,
                'rejection_reason' => null,
                'claim_token' => null,
                'default_event_settings' => null,
                'stats_cache' => null,
            ]);
        }
    }
}
