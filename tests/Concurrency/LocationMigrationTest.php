<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('upgrades an existing coarse location schema and backfills from the encrypted source', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->decimal('location_lat', 5, 2)->nullable()->change();
        $table->decimal('location_lng', 6, 2)->nullable()->change();
        $table->renameIndex('users_location_index', 'users_coarse_location_index');
    });
    $user = User::factory()->create(['remembered_location' => ['lat' => 45.1234567, 'lng' => 11.7654321], 'location_expires_at' => now()->addMonth()]);
    $migration = require database_path('migrations/2026_09_24_090000_add_queryable_location_to_users.php');
    $migration->up();
    expect(Schema::hasIndex('users', 'users_location_index'))->toBeTrue()
        ->and(Schema::hasIndex('users', 'users_coarse_location_index'))->toBeFalse()
        ->and((float) $user->fresh()->location_lat)->toBe(45.1234567)
        ->and((float) $user->fresh()->location_lng)->toBe(11.7654321);
    DB::table('migrations')->insert(['migration' => '2026_09_24_090000_add_coarse_location_to_users', 'batch' => 0]);
    $migration->down();
    expect((float) $user->fresh()->location_lat)->toBe(45.1234567)
        ->and(Schema::hasIndex('users', 'users_coarse_location_index'))->toBeTrue();
    DB::table('migrations')->where('migration', '2026_09_24_090000_add_coarse_location_to_users')->delete();
    $migration->up();
});
