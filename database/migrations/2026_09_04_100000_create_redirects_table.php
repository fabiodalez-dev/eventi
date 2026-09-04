<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli indirizzi che non esistono più e dove vanno a finire.
 *
 * **Il problema è già in produzione, silenzioso.** `Event`, `Venue`,
 * `Category`, `Tag` e `City` generano lo slug con `HasSlug` senza
 * `doNotGenerateSlugsOnUpdate()` — che solo `Page` ha — e il campo è anche
 * modificabile a mano dal pannello. Correggere un refuso nel titolo di un
 * evento gli cambia quindi l'indirizzo, e da quel momento ogni link condiviso,
 * ogni pagina indicizzata e ogni QR stampato su una locandina rispondono 404.
 * Nessuno se ne accorge: chi rinomina vede la pagina nuova.
 *
 * **Perché una tabella e non un array in `config/`.** Il redirector di Spatie
 * legge di suo un elenco in configurazione, che qui non servirebbe a niente:
 * gli slug cambiano in redazione, non al rilascio, e chi li cambia non ha
 * accesso a un file da ridistribuire. Le righe le scrivono gli observer al
 * momento della rinomina, e la redazione può aggiungerne a mano dal pannello
 * per gli indirizzi che non hanno più un modello dietro — una sezione tolta,
 * un indirizzo finito su un volantino.
 *
 * **Perché il prefisso di città sta fuori da `from_path`.** Le rotte pubbliche
 * sono registrate due volte, nude e sotto `/{city}` (§11.1): tenere il
 * prefisso dentro il dato vorrebbe dire due righe per ogni rinomina, che prima
 * o poi diventano una sola per distrazione. Qui `from_path` è
 * `/eventi/vecchio-slug` e il prefisso lo rimette in piedi chi legge, esatto
 * com'era nella richiesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();

            /*
             * A quale spazio di indirizzi appartiene l'origine. Nullo vuol dire
             * «vale per tutte»: gli slug dei locali sono unici in tutto il
             * sistema (D12), e categorie e tag non appartengono a una città.
             */
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            /* 191 e non 255: è il limite oltre il quale un indice su utf8mb4
               non ci sta in una chiave da 767 byte sulle versioni più vecchie
               di MySQL, e questa colonna vive dentro un indice. */
            $table->string('from_path', 191);
            $table->string('to_path', 191);

            /*
             * Una riga con il jolly copre un ramo intero: `/vecchia-citta/*` →
             * `/nuova-citta/{wildcard}`. È il solo modo sensato di trattare la
             * rinomina di una città, dove le righe una-per-indirizzo sarebbero
             * quante sono le pagine del sito. Sta in una colonna e non
             * indovinato dall'asterisco perché è ciò su cui si filtra: le righe
             * esatte si cercano con un uguale, quelle jolly si confrontano una
             * per una, e sono poche solo finché si possono contare.
             */
            $table->boolean('is_wildcard')->default(false);

            /* 301 quasi sempre. Il 302 esiste per gli spostamenti dichiarati
               temporanei, che un motore di ricerca non deve trattare come
               definitivi. */
            $table->unsignedSmallInteger('status')->default(301);

            /*
             * Quante volte è servita e quando l'ultima. Senza questi due numeri
             * l'elenco diventa in fretta un deposito che nessuno osa potare: è
             * la differenza fra sapere che un vecchio indirizzo è ancora il più
             * visitato del sito e sospettarlo.
             */
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            $table->datetimes();

            /*
             * Due destinazioni per la stessa origine sono un errore, non un
             * caso da gestire. L'unicità copre le righe di una città; per
             * quelle senza (`city_id` nullo) MySQL considera ogni NULL diverso
             * da ogni altro e non le vincola — è il motivo per cui a scrivere
             * è sempre `RegistroRedirect`, che cerca prima di inserire, e per
             * cui chi legge prende comunque una riga sola.
             */
            $table->unique(['city_id', 'from_path']);
            $table->index('is_wildcard');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
