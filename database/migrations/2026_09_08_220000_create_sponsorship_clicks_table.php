<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sponsorship_id')->constrained()->cascadeOnDelete();
            $table->string('request_key', 64)->unique();
            $table->string('channel', 16);
            $table->string('placement', 32);
            $table->string('page', 32);
            $table->timestamp('clicked_at')->index();
            $table->index(['sponsorship_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_clicks');
    }
};
