<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConsentCategory;
use App\Models\CookieDeclaration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Il registro dei cookie che questo sito deposita **davvero**.
 *
 * Non un elenco di esempio: ogni riga è stata verificata nel codice. Un
 * registro con dentro voci inventate è peggio di un registro vuoto, perché
 * dichiara a chi legge un comportamento che il sito non ha — e chi controlla
 * cerca proprio quei nomi.
 *
 * Le due finalità facoltative sono **vuote**, ed è un'informazione, non una
 * dimenticanza: l'analitica di questo sito non usa cookie (conta le pagine
 * lato server) e le sponsorizzazioni contano visualizzazioni e clic senza
 * depositare niente nel browser. Chi legge una policy vuole sapere anche cosa
 * il sito non fa.
 */
final class CookieDeclarationSeeder extends Seeder
{
    public function run(): void
    {
        $sessione = Str::slug((string) config('app.name', 'laravel')).'-session';

        $righe = [
            [
                'category' => ConsentCategory::Necessary,
                'name' => $sessione,
                'provider' => null,
                'purpose' => 'Tiene il filo della visita: ricorda che hai fatto l\'accesso e mostra i messaggi di conferma una volta sola.',
                'duration' => 'Fino alla chiusura della sessione',
                'sort_order' => 10,
            ],
            [
                'category' => ConsentCategory::Necessary,
                'name' => 'XSRF-TOKEN',
                'provider' => null,
                'purpose' => 'Impedisce che un altro sito invii moduli al posto tuo.',
                'duration' => 'Durata della sessione',
                'sort_order' => 20,
            ],
            [
                'category' => ConsentCategory::Necessary,
                'name' => 'consenso',
                'provider' => null,
                'purpose' => 'Ricorda la scelta che hai fatto sui cookie, così non te la chiediamo a ogni pagina.',
                'duration' => '180 giorni',
                'sort_order' => 30,
            ],
            [
                'category' => ConsentCategory::Necessary,
                'name' => 'salvataggi',
                'provider' => null,
                'purpose' => 'Le date che metti in agenda senza un account. Restano nel tuo browser: non arrivano a noi e spariscono se cancelli i dati del sito.',
                'duration' => 'Finché non cancelli i dati del browser',
                'sort_order' => 40,
            ],
        ];

        foreach ($righe as $riga) {
            CookieDeclaration::query()->updateOrCreate(
                ['category' => $riga['category'], 'name' => $riga['name']],
                $riga,
            );
        }
    }
}
