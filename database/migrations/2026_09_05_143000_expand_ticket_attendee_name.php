<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_tickets', fn (Blueprint $table) => $table->string('attendee_name', 255)->change());
    }

    public function down(): void
    {
        // Preserve the wider column: narrowing would destroy existing names.
    }
};
