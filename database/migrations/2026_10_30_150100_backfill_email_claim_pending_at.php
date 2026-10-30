<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Gli account registrati prima di `email_claim_pending_at` e mai confermati
 * hanno la colonna vuota: entravano con la password anche dopo il D64, e chi
 * rifaceva la registrazione riceveva «hai già un account» invece del
 * collegamento che conferma. Per loro vale ciò che vale per una
 * registrazione nuova: la password l'ha scelta chiunque abbia scritto
 * quell'indirizzo, finché la casella non è dimostrata. La data è quella di
 * nascita dell'account; gli eliminati restano come sono. Restano fuori anche
 * i domini riservati che non ricevono posta (`.test`, `.invalid`,
 * `.example`, `.localhost`, `example.com/net/org`): sono account di prova e
 * della vetrina creati dalla redazione, e una casella che non esiste non
 * si può dimostrare — resterebbero chiusi fuori per sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNull('deleted_at')
            ->whereNull('email_claim_pending_at')
            ->where(function ($query): void {
                foreach (['%.test', '%.invalid', '%.example', '%.localhost', '%@example.com', '%@example.net', '%@example.org'] as $riservato) {
                    $query->where('email', 'not like', $riservato);
                }
            })
            ->update(['email_claim_pending_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    /* Le righe toccate qui sono quelle con il sospeso uguale alla nascita e
       l'indirizzo ancora da confermare: tornano vuote. */
    public function down(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereColumn('email_claim_pending_at', 'created_at')
            ->update(['email_claim_pending_at' => null]);
    }
};
