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
        Schema::table('devices', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable()->after('push_token');
            $table->string('installation_id', 64)->nullable()->after('platform');
            $table->index(['user_id', 'installation_id']);
        });

        /* Le installazioni gia' registrate ricevono subito la stessa identita'
           sicura delle nuove. Se lo stesso token esiste piu' volte, resta
           attiva soltanto la riga piu' recente: un token FCM appartiene a una
           sola installazione e non deve mai spedire a due account. */
        $seen = [];

        foreach (DB::table('devices')->whereNotNull('push_token')->orderByDesc('id')->get(['id', 'push_token']) as $device) {
            $token = is_string($device->push_token) ? $device->push_token : '';

            if ($token === '') {
                continue;
            }

            $hash = hash('sha256', $token);

            if (isset($seen[$hash])) {
                DB::table('devices')->where('id', $device->id)->update([
                    'push_token' => null,
                    'revoked_at' => now(),
                ]);

                continue;
            }

            $seen[$hash] = true;
            DB::table('devices')->where('id', $device->id)->update(['token_hash' => $hash]);
        }

        Schema::table('devices', function (Blueprint $table): void {
            $table->unique('token_hash');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('device_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained('devices')
                ->nullOnDelete();
        });

        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->softDeletesDatetime();
        });

        Schema::create('mobile_auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at')->index();
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_auth_challenges');

        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'installation_id']);
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'installation_id']);
        });
    }
};
