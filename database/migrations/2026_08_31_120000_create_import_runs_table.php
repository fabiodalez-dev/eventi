<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo storico delle esecuzioni di import (§14.2: «log errori», §14.5: «import
 * falliti»).
 *
 * `import_sources.last_*` risponde a «come è andata l'ultima volta» e a
 * nient'altro: non dice da quando una sorgente è ferma, né quante date aveva
 * portato ieri, né se il calendario ha cominciato a perdere pezzi. Sono
 * esattamente le domande che si pone chi ha collegato il proprio calendario e
 * non vede comparire una serata.
 *
 * Le colonne dell'ultima esecuzione restano dove sono: sono l'indice, non il
 * duplicato — la dashboard e l'elenco le leggono senza toccare questa tabella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_source_id')->constrained('import_sources')->cascadeOnDelete();
            $table->string('status', 32);

            // I sei contatori di `App\DTOs\ImportReport`, uno per colonna:
            // servono a essere sommati e confrontati, non solo a essere letti.
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);

            $table->text('message')->nullable();
            $table->datetimes();

            $table->index(['import_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
