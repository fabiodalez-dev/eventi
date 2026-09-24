<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_tickets', function (Blueprint $table): void {
            $table->timestamp('wallet_requested_at')->nullable();
            $table->uuid('checkin_request_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admission_tickets', fn (Blueprint $table) => $table->dropColumn(['wallet_requested_at', 'checkin_request_key']));
    }
};
