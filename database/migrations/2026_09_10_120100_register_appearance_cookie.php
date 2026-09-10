<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['incitta_appearance', 'Ricorda il tema chiaro o scuro, inizialmente rilevato dal sistema e modificabile da Aspetto. Non è usato per pubblicità o tracciamento.', '1 anno dall’ultima visita', 50],
            ['incitta:appearance:guest (localStorage)', 'Copia locale di supporto della preferenza del tema per i visitatori.', 'Fino alla cancellazione dei dati del sito', 51],
        ] as [$name, $purpose, $duration, $order]) {
            if (! DB::table('cookie_declarations')->where('name', $name)->exists()) {
                DB::table('cookie_declarations')->insert([
                    'category' => 'necessary', 'name' => $name, 'provider' => null,
                    'purpose' => $purpose, 'duration' => $duration, 'sort_order' => $order,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('cookie_declarations')->whereIn('name', ['incitta_appearance', 'incitta:appearance:guest (localStorage)'])->delete();
    }
};
