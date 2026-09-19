<?php

use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Lo schema viene davvero modificato (su MariaDB il DDL non sta in una
 * transazione): il file gira da solo sul database dedicato, come la prova di
 * concorrenza dei passaggi.
 */
uses(TestCase::class)->beforeEach(function (): void {
    expect(config('database.connections.mysql.database'))->toBe('eventi_test_carpool_race');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    // Si torna allo schema delle sole recensioni dei locali.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
    expect(Schema::hasTable('venue_reviews'))->toBeTrue()->and(Schema::hasTable('reviews'))->toBeFalse();
});

function legacyVenueReviews(): array
{
    $venue = Venue::factory()->approved()->create(['city_id' => testCity()->id]);
    $rows = [];
    foreach ([[5, 'approved'], [2, 'pending'], [4, 'rejected']] as [$rating, $status]) {
        $rows[] = DB::table('venue_reviews')->insertGetId(['venue_id' => $venue->id, 'user_id' => User::factory()->create()->id, 'rating' => $rating,
            'body' => 'Recensione di prova con voto '.$rating, 'status' => $status, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $rows;
}

function assertUnifiedReviews(array $ids): void
{
    expect(Schema::hasTable('venue_reviews'))->toBeFalse()
        ->and(Schema::hasColumn('reviews', 'rating'))->toBeFalse()
        ->and(Schema::hasColumns('reviews', ['reviewable_type', 'reviewable_id', 'review', 'department', 'recommend', 'approved']))->toBeTrue()
        ->and(Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'user_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'approved']))->toBeTrue();
    expect(DB::table('ratings')->where('key', 'overall')->orderBy('review_id')->pluck('value', 'review_id')->all())
        ->toBe([$ids[0] => 5, $ids[1] => 2, $ids[2] => 4]);
    expect(DB::table('reviews')->where('approved', true)->pluck('id')->all())->toBe([$ids[0]])
        ->and(DB::table('reviews')->where('reviewable_type', 'venue')->count())->toBe(3);
}

it('completes a unification interrupted right after the table rename', function (): void {
    $ids = legacyVenueReviews();
    // Stato lasciato da un'esecuzione caduta subito dopo il cambio di nome.
    Schema::table('venue_reviews', function (Blueprint $table): void {
        $table->dropForeign(['venue_id']);
        $table->dropUnique(['venue_id', 'user_id']);
        $table->dropIndex(['venue_id', 'status', 'created_at']);
        $table->renameColumn('venue_id', 'reviewable_id');
        $table->renameColumn('body', 'review');
    });
    Schema::rename('venue_reviews', 'reviews');

    (require database_path('migrations/2026_09_18_180600_unify_catalog_reviews.php'))->up();
    assertUnifiedReviews($ids);
});

it('completes a unification interrupted halfway through the column changes', function (): void {
    $ids = legacyVenueReviews();
    // Caduta a metà del primo blocco: vincolo tolto, colonne ancora da rinominare.
    Schema::table('venue_reviews', fn (Blueprint $table) => $table->dropForeign(['venue_id']));

    (require database_path('migrations/2026_09_18_180600_unify_catalog_reviews.php'))->up();
    assertUnifiedReviews($ids);
});

it('runs the unification from scratch, again as a no-op, and rolls back', function (): void {
    $ids = legacyVenueReviews();
    $migration = require database_path('migrations/2026_09_18_180600_unify_catalog_reviews.php');
    $migration->up();
    $migration->up();
    assertUnifiedReviews($ids);
    $migration->down();
    expect(Schema::hasTable('reviews'))->toBeFalse()
        ->and(DB::table('venue_reviews')->orderBy('id')->pluck('rating')->all())->toBe([5, 2, 4]);
});
