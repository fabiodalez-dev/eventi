<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')->where('verification_status', '!=', 'editorial_checked')
            ->update(['verification_status' => 'unverified']);
        DB::table('events')->where('verification_status', '!=', 'editorial_checked')
            ->whereIn('venue_id', DB::table('venues')->select('id')->where('is_verified', true)->whereNull('deleted_at'))
            ->update(['verification_status' => 'venue_confirmed']);
    }

    public function down(): void
    {
        // The derived confirmation is not reverted to potentially stale values.
    }
};
