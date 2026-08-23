<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_recurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('rrule', 500);
            $table->dateTime('until')->nullable();
            $table->json('exdates')->nullable();
            $table->dateTime('generated_until')->nullable();
            $table->datetimes();

            $table->index('generated_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_recurrences');
    }
};
