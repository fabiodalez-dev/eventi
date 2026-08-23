<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->string('venue_name');
            $table->string('contact_name');
            $table->string('contact_role')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email');
            $table->string('address')->nullable();
            $table->enum('type', [
                'bar', 'pub', 'circolo', 'centro_sociale', 'club', 'teatro', 'cinema',
                'libreria', 'associazione', 'galleria', 'spazio_pubblico', 'ristorante', 'altro',
            ])->default('altro');
            $table->json('socials')->nullable();
            $table->text('message')->nullable();
            $table->json('documents')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->datetimes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_applications');
    }
};
