<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table): void {
            $table->timestamp('passenger_confirmed_at')->nullable();
        });
        Schema::table('carpool_cases', function (Blueprint $table): void {
            $table->longText('review_snapshot')->nullable();
        });
        Schema::create('ride_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ride_request_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('body')->nullable();
            $t->string('status', 20)->default('published');
            $t->timestamp('moderated_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['driver_id', 'status', 'created_at']);
        });
        $privacy = file_get_contents(resource_path('legal/carpool/privacy-2026-09-18.it.md'));
        $heading = '### Recensioni volontarie del conducente';
        $section = substr($privacy, strpos($privacy, $heading));
        foreach (DB::table('pages')->where('slug', 'privacy')->get() as $page) {
            if (! str_contains($page->body, $heading)) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $page->body."\n\n".$section, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_reviews');
        Schema::table('ride_requests', fn (Blueprint $t) => $t->dropColumn('passenger_confirmed_at'));
        Schema::table('carpool_cases', fn (Blueprint $t) => $t->dropColumn('review_snapshot'));
    }
};
