<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I commenti alla scheda di un evento.
 *
 * ## Perché assomiglia a `venue_reviews` e non a una tabella nuova
 *
 * La moderazione qui è già stata risolta una volta: stato, chi ha deciso,
 * quando, con quale nota, e un numero di revisione che impedisce a due
 * moderatori di sovrascriversi a vicenda. Ripetere quella forma significa che
 * chi ha imparato le recensioni sa già leggere i commenti — e che la risorsa
 * Filament si scrive ricalcando quella che esiste.
 *
 * ## Le due differenze che contano
 *
 * 1. **Si pubblica subito.** Le recensioni nascono `pending` e aspettano un
 *    admin; un commento no. Una risposta che compare tre ore dopo non è una
 *    conversazione, è un archivio. Lo stato predefinito è quindi `published`,
 *    e la moderazione interviene dopo, su segnalazione o su controllo.
 * 2. **Non c'è `unique`.** Una recensione per locale per persona ha senso —
 *    un commento per evento per persona no.
 *
 * ## `parent_id`, un livello solo
 *
 * Una risposta si attacca a un commente di primo livello. Rispondere a una
 * risposta riporta allo stesso capostipite, citando chi si sta interpellando.
 * L'albero infinito costa di più da leggere che da scrivere: su uno schermo
 * stretto, al terzo livello di rientro non resta larghezza per il testo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Cancellando un commento spariscono le sue risposte: una risposta
             * senza la domanda è un frammento che nessuno sa più leggere.
             */
            $table->foreignId('parent_id')->nullable()->constrained('event_comments')->cascadeOnDelete();

            $table->text('body');
            $table->string('status', 20)->default('published');
            $table->unsignedInteger('revision')->default(1);

            /*
             * Chi ha nascosto il commento, e quando. Il locale ha un interesse
             * diretto nei commenti sui propri eventi: la sua moderazione deve
             * lasciare traccia, o non è distinguibile dalla censura.
             */
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();

            /*
             * Denormalizzato di proposito: il conteggio si legge su ogni riga
             * di ogni pagina, e un `COUNT` per commento sarebbe una query in
             * più per riga. Lo tiene allineato `ToggleReaction`, dentro la
             * stessa transazione che scrive la reazione.
             */
            $table->unsignedInteger('reactions_count')->default(0);

            $table->datetimes();

            /* L'elenco di una pagina: evento, stato, ordine cronologico. */
            $table->index(['event_id', 'status', 'id']);

            /* Le risposte di un commento, e «i miei commenti». */
            $table->index(['parent_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_comments');
    }
};
