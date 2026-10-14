<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('placement');
            $table->unsignedTinyInteger('months');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['placement', 'months']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_prices');
    }
};
