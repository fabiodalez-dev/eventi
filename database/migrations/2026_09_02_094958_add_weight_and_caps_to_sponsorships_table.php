<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il peso in rotazione e i due tetti di consegna.
 *
 * **Perche' `priority` non bastava.** Era un ordine: chi ce l'ha piu' alta sta
 * davanti. Nella collocazione in apertura, che ammette **una sola** campagna,
 * questo vuol dire che la piu' alta prende il cento per cento delle
 * apparizioni e le altre non compaiono mai — anche se hanno pagato. Fra due
 * clienti si puo' vendere solo «il primo posto», e una volta venduto non c'e'
 * piu' niente da vendere.
 *
 * Il peso e' un'altra cosa: peso 3 contro peso 1 non significa «sta davanti»,
 * significa «compare tre volte su quattro». E' cio' che permette di vendere
 * una **quota** invece di una posizione, e di avere piu' clienti sullo stesso
 * spazio senza che nessuno resti a zero.
 *
 * `priority` resta e continua a fare il suo mestiere: decide l'ordine quando
 * una collocazione ne mostra piu' d'una insieme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorships', function (Blueprint $table): void {
            /*
             * Default 1 e non 0: con peso zero una campagna non comparirebbe
             * mai, e sarebbe un modo silenzioso di spegnerla che nessuno si
             * aspetta da un campo chiamato «peso». Per spegnerla c'e' lo stato.
             */
            $table->unsignedSmallInteger('weight')->default(1)->after('priority');

            /*
             * I tetti di consegna: `null` significa «nessun tetto», che e' il
             * comportamento di oggi e resta quello predefinito. Una campagna
             * che li raggiunge smette di comparire ma NON cambia stato: e'
             * finita per esaurimento, non sospesa da qualcuno, e la differenza
             * si deve poter leggere.
             */
            $table->unsignedBigInteger('impressions_cap')->nullable()->after('impressions');
            $table->unsignedBigInteger('clicks_cap')->nullable()->after('clicks');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'impressions_cap', 'clicks_cap']);
        });
    }
};
