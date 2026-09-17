<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_comments', function (Blueprint $table): void {
            $table->foreignId('reply_to_id')->nullable()->constrained('event_comments')->nullOnDelete();
            $table->dropColumn('reactions_count');
        });
        DB::table('event_comments')->whereNotNull('parent_id')->update(['reply_to_id' => DB::raw('parent_id')]);
    }

    public function down(): void
    {
        Schema::table('event_comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->unsignedInteger('reactions_count')->default(0);
        });
        DB::statement('UPDATE event_comments SET reactions_count = (SELECT COUNT(*) FROM event_comment_reactions WHERE event_comment_id = event_comments.id)');
    }
};
