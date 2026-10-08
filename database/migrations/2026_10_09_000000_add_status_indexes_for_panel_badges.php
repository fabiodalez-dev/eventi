<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli indici che mancavano alle interrogazioni «quante righe in questo
 * stato»: i contatori del menu di redazione, le code di moderazione, l'elenco
 * degli avvisi di una persona. Gli indici esistenti partivano tutti da un'altra
 * colonna (`city_id`, `event_id`, `reviewable_type`) e per un filtro sul solo
 * stato il database leggeva la tabella intera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->indexIfMissing('events', ['status'], 'events_status_index');
        $this->indexIfMissing('venues', ['status'], 'venues_status_index');
        $this->indexIfMissing('reviews', ['status'], 'reviews_status_index');
        $this->indexIfMissing('event_comments', ['status'], 'event_comments_status_index');
        $this->indexIfMissing('carpool_cases', ['closed_at'], 'carpool_cases_closed_at_index');
        $this->indexIfMissing('notifications', ['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_at_index');
    }

    public function down(): void
    {
        foreach ([
            'events' => 'events_status_index',
            'venues' => 'venues_status_index',
            'reviews' => 'reviews_status_index',
            'event_comments' => 'event_comments_status_index',
            'carpool_cases' => 'carpool_cases_closed_at_index',
            'notifications' => 'notifications_notifiable_created_at_index',
        ] as $table => $index) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    /** @param  list<string>  $columns */
    private function indexIfMissing(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }
};
