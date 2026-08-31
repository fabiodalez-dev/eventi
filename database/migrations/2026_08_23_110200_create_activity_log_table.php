<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Il verso di ritorno manca nel file pubblicato dal pacchetto: senza,
     * `migrate:rollback` segna la migrazione come disfatta e lascia la
     * tabella al suo posto, cosi la migrazione successiva non riparte
     * (`RUNBOOK.md`, «Rollback»).
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
