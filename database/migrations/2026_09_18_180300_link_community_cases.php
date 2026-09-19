<?php

declare(strict_types=1);
use App\Models\Report;
use App\Services\Carpool\CommunitySafety;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carpool_mutexes', function (Blueprint $t): void {
            $t->string('name')->primary();
        });
        DB::table('carpool_mutexes')->insert(['name' => 'retention']);
        Schema::table('carpool_cases', function (Blueprint $t): void {
            $t->foreignId('source_report_id')->nullable()->unique()->constrained('reports')->nullOnDelete();
            $t->timestamp('purged_at')->nullable();
        });
        Schema::table('ride_offers', fn (Blueprint $t) => $t->timestamp('purged_at')->nullable());
        Report::whereIn('reportable_type', ['community_profile', 'community_post', 'community_comment'])->chunkById(100, function ($rows): void {
            foreach ($rows as $report) {
                app(CommunitySafety::class)->importReport($report);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carpool_mutexes');
        Schema::table('carpool_cases', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('source_report_id');
            $t->dropColumn('purged_at');
        });
        Schema::table('ride_offers', fn (Blueprint $t) => $t->dropColumn('purged_at'));
    }
};
