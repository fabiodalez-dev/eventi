<?php

use App\Support\ContentVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Match the original seed text and price, never overwrite edited/real events.
        $events = DB::table('events')->where('slug', 'concerto-trio-acustico')
            ->where('title', 'Concerto: Trio Acustico')->where('subtitle', 'Serata dal vivo già iniziata')
            ->where('description', "Un trio acustico che suona da mezz'ora davanti a un pubblico raccolto. Ingresso libero, cassa per le consumazioni al bancone.")
            ->where('short_description', 'Concerto acustico in corso, ingresso libero.')
            ->where('price_type', 'ticket')->where('price_min', 8)->where('price_max', 8);
        $cities = (clone $events)->pluck('city_id');
        $events->update(['is_demo' => true, 'subtitle' => 'Serata di musica acustica',
            'description' => 'Un trio acustico dal vivo davanti a un pubblico raccolto. Biglietto di ingresso: 8 euro; consumazioni al bancone.',
            'short_description' => 'Concerto acustico dal vivo. Ingresso: 8 euro.', 'updated_at' => now()]);
        foreach ($cities->unique() as $id) {
            ContentVersion::bump((int) $id);
        }
    }

    public function down(): void
    {
        // Editorial corrections are retained: a rollback must not restore false prices.
    }
};
