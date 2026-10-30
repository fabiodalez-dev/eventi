<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * L'invito in un locale si accetta (D64, «Rinviato, da decidere», chiuso il
 * 30 ottobre): da qui in poi una riga di `venue_user` con `accepted_at` vuoto
 * è un invito in sospeso, che non apre il pannello, non fa arrivare le email
 * dello staff e non mostra il nome della persona al locale.
 *
 * `accepted_at` esisteva dal primo giorno ma nessuno la scriveva: tutte le
 * appartenenze di oggi sono vuote lì e sono, nei fatti, accettate. Si
 * riempiono con la data dell'invito (o della riga, o di adesso), altrimenti
 * il deploy toglierebbe il pannello a ogni referente e collaboratore.
 *
 * `accepts_on_password` segna l'invito che ha **creato** l'account: per
 * quello la prova è la password scelta dal collegamento del messaggio (è
 * lì che la persona dimostra la casella), e non serve un secondo clic.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Rieseguibile: il riempimento qui sotto è la parte che conta, e
           ripeterlo su righe già piene non cambia niente. */
        if (! Schema::hasColumn('venue_user', 'accepts_on_password')) {
            Schema::table('venue_user', function (Blueprint $table): void {
                $table->boolean('accepts_on_password')->default(false)->after('accepted_at');
            });
        }

        /* Solo le righe nate prima degli inviti da accettare: dopo il deploy
           un rollback seguito da una nuova esecuzione non deve trasformare in
           appartenenze gli inviti veri ancora in sospeso. */
        DB::table('venue_user')
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('created_at')->orWhere('created_at', '<', '2026-10-31 00:00:00'))
            ->update(['accepted_at' => DB::raw('COALESCE(invited_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        /* Il riempimento non si annulla: rimettere a vuoto `accepted_at`
           trasformerebbe in inviti appartenenze vere. */
        Schema::table('venue_user', fn (Blueprint $table) => $table->dropColumn('accepts_on_password'));
    }
};
