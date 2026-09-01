<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le campagne sponsorizzate.
 *
 * **Perché una tabella e non due colonne su `events`.** Un evento in evidenza
 * lo decide la redazione, ed è una proprietà dell'evento: `is_featured` e
 * `featured_until` stanno lì e va bene così. Una sponsorizzazione è un'altra
 * cosa — è un contratto con qualcuno, ha un committente che può non essere il
 * locale, un importo, un periodo, uno storico da rendicontare, e lo stesso
 * evento può esserne oggetto più volte in campagne diverse. Schiacciarla in
 * due colonne significa perdere tutto questo alla prima domanda
 * dell'amministrazione («quanto abbiamo fatturato a marzo?»).
 *
 * **Le metriche stanno qui e non sull'evento** per la stessa ragione: mille
 * visualizzazioni appartengono alla campagna che le ha pagate, non all'evento
 * che le ha ricevute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorships', function (Blueprint $table): void {
            $table->id();

            /* La città è denormalizzata dall'evento perché la selezione delle
               campagne attive parte SEMPRE dalla città: senza, ogni pagina
               dovrebbe unire gli eventi per filtrare. */
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('placement', SponsorshipPlacement::values());
            $table->enum('status', SponsorshipStatus::values())->default(SponsorshipStatus::Draft->value);

            /* La finestra in UTC, come tutti gli istanti del sistema (§8): la
               conversione al fuso della città avviene in lettura. */
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            /* A parità di finestra e collocazione, chi ha priorità più alta
               compare per primo. Le altre restano in rotazione. */
            $table->unsignedSmallInteger('priority')->default(0);

            /* Chi paga. Può non essere il locale: un'etichetta discografica
               sponsorizza il concerto in un circolo che non è suo. Il nome è
               obbligatorio perché è quello che va dichiarato a chi guarda —
               «sponsorizzato» senza dire da chi è mezza informazione. */
            $table->string('advertiser_name');
            $table->string('advertiser_email')->nullable();
            $table->string('advertiser_url')->nullable();

            /* Amministrazione. L'importo in centesimi, come ogni denaro nel
               sistema: i decimali in virgola mobile sommati mille volte non
               tornano. */
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('invoice_reference')->nullable();

            /* Misure. Contatori grezzi: il rapporto fra i due lo calcola chi
               legge, perché una percentuale salvata invecchia male. */
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /* L'indice della domanda che si fa a ogni pagina: «quali campagne
               di questa città, per questa collocazione, sono attive adesso?».
               L'ordine delle colonne è quello in cui i filtri si stringono. */
            $table->index(['city_id', 'placement', 'status', 'starts_at', 'ends_at'], 'sponsorships_selection_index');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorships');
    }
};
