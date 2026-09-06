<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_scripts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->text('src')->nullable();
            $table->text('code')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_scripts');
    }
};
