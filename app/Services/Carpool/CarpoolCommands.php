<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\EventOccurrence;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class CarpoolCommands
{
    /** @param array<string, mixed> $payload
     * @param  Closure(User): int  $operation
     */
    public function run(User $actor, string $key, string $action, array $payload, ?int $occurrenceId, Closure $operation): int
    {
        app(CarpoolAccess::class)->notImpersonating();
        $hash = hash('sha256', json_encode([$action, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $key, $action, $hash, $occurrenceId, $operation): int {
            // Current reads and per-date serialization protect both capacity and cross-offer exclusivity.
            if ($occurrenceId !== null) {
                EventOccurrence::withTrashed()->whereKey($occurrenceId)->lockForUpdate()->firstOrFail();
            }
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('carpool_commands')->where('user_id', $user->id)->where('request_key', $key)->lockForUpdate()->first();
            if ($old !== null) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, __('carpool.errors.retry'));

                return (int) $old->result_id;
            }
            $result = $operation($user);
            DB::table('carpool_commands')->insert(['user_id' => $user->id, 'request_key' => $key, 'action' => $action,
                'payload_hash' => $hash, 'result_id' => $result, 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 5);
    }
}
