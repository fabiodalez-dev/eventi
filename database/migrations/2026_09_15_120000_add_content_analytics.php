<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_views_daily', function (Blueprint $table): void {
            foreach (['website_clicks', 'phone_clicks', 'email_clicks', 'calendar_clicks', 'poster_clicks', 'booking_clicks'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
        });
        Schema::create('profile_views_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('profile_type', 16);
            $table->unsignedBigInteger('profile_id');
            $table->date('date');
            foreach (['views', 'direction_clicks', 'ticket_clicks', 'shares', 'website_clicks', 'phone_clicks', 'email_clicks', 'calendar_clicks', 'poster_clicks', 'booking_clicks'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->timestamps();
            $table->unique(['profile_type', 'profile_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_views_daily');
        Schema::table('event_views_daily', function (Blueprint $table): void {
            $table->dropColumn(['website_clicks', 'phone_clicks', 'email_clicks', 'calendar_clicks', 'poster_clicks', 'booking_clicks']);
        });
    }
};
