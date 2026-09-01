<?php

declare(strict_types=1);

use App\Enums\AccessibilityFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `venues.accessibility` smette di essere un JSON libero.
 *
 * Fino a oggi conteneva un solo interruttore, `{"wheelchair": true}`, scritto
 * dai seeder e da nessun'altra parte: nessun modulo lo compilava e nessuna
 * pagina lo mostrava, tranne un distintivo sulla scheda del locale. Da qui in
 * avanti le chiavi sono quelle di `App\Enums\AccessibilityFeature`, e
 * `wheelchair` diventa `step_free_entrance` — l'unica cosa che quel campo
 * abbia mai voluto dire.
 *
 * La riscrittura è necessaria e non cosmetica: il filtro di §11.3 interroga la
 * chiave in SQL (`accessibility->step_free_entrance`), e una riga rimasta alla
 * vecchia chiave sparirebbe in silenzio dai risultati. Il valore `false` si
 * conserva: «dichiarato assente» non è «non dichiarato».
 *
 * `App\DTOs\AccessibilityProfile` continua a capire la vecchia forma in
 * lettura, perché i dati arrivano anche da un backup ripristinato o da un
 * import, non solo da questa tabella.
 */
return new class extends Migration
{
    private const LEGACY_KEY = 'wheelchair';

    public function up(): void
    {
        $this->rewrite(self::LEGACY_KEY, AccessibilityFeature::StepFreeEntrance->value);
    }

    public function down(): void
    {
        $this->rewrite(AccessibilityFeature::StepFreeEntrance->value, self::LEGACY_KEY);
    }

    private function rewrite(string $from, string $to): void
    {
        DB::table('venues')
            ->select(['id', 'accessibility'])
            ->whereNotNull('accessibility')
            ->orderBy('id')
            ->chunk(200, function (iterable $venues) use ($from, $to): void {
                foreach ($venues as $venue) {
                    $decoded = json_decode((string) $venue->accessibility, true);

                    if (! is_array($decoded) || ! array_key_exists($from, $decoded)) {
                        continue;
                    }

                    $decoded[$to] = filter_var($decoded[$from], FILTER_VALIDATE_BOOLEAN);
                    unset($decoded[$from]);

                    DB::table('venues')
                        ->where('id', $venue->id)
                        ->update(['accessibility' => json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                }
            });
    }
};
