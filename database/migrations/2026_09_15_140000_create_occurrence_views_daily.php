<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrence_views_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->date('date');
            foreach (['views', 'direction_clicks', 'ticket_clicks', 'shares', 'website_clicks', 'phone_clicks', 'email_clicks', 'calendar_clicks', 'poster_clicks', 'booking_clicks'] as $metric) {
                $table->unsignedInteger($metric)->default(0);
            }
            $table->timestamps();
            $table->unique(['occurrence_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrence_views_daily');
    }
};
