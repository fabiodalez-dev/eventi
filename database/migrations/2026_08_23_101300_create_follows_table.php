<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('followable_type', 64);
            $table->unsignedBigInteger('followable_id');
            $table->boolean('notify')->default(true);
            $table->datetimes();

            $table->unique(['user_id', 'followable_type', 'followable_id'], 'follows_user_followable_unique');
            $table->index(['followable_type', 'followable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
