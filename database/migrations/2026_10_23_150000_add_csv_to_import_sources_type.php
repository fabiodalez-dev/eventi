<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il tipo `csv` fra le sorgenti di import: il foglio di un teatro, un Google
 * Sheet pubblicato come CSV, un file caricato dal pannello. La colonna è un
 * ENUM di MySQL, quindi il valore nuovo va dichiarato qui e non solo nel
 * `ImportSourceType` di PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE import_sources MODIFY type ENUM('ics', 'csv', 'json', 'rss', 'api', 'manual') NOT NULL DEFAULT 'ics'");
    }

    public function down(): void
    {
        DB::table('import_sources')->where('type', 'csv')->update(['type' => 'manual']);
        DB::statement("ALTER TABLE import_sources MODIFY type ENUM('ics', 'json', 'rss', 'api', 'manual') NOT NULL DEFAULT 'ics'");
    }
};
