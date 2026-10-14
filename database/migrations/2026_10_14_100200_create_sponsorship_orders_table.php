<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un ordine di sponsorizzazione: chi, cosa, quanto, e a che punto e'.
 *
 * Posizione, durata e importo sono **copie** del listino al momento
 * dell'acquisto: il listino cambia, l'ordine no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sponsorship_price_id')->nullable()->constrained()->nullOnDelete();
            $table->string('placement');
            $table->string('mode');
            $table->unsignedTinyInteger('months');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('status');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignId('sponsorship_grant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_orders');
    }
};
