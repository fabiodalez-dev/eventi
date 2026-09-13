<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['venues', 'organizers'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('contact_mode', 20)->default('disabled');
                $table->string('contact_email')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['venues', 'organizers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['contact_mode', 'contact_email']));
        }
    }
};
