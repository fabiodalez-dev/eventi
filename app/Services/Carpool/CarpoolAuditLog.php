<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\CarpoolAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CarpoolAuditLog
{
    /** @param array<string, mixed> $metadata */
    public function record(?User $actor, string $action, ?Model $subject, array $metadata = []): CarpoolAudit
    {
        $ip = request()->ip();
        $packed = $ip ? @inet_pton($ip) : false;

        return CarpoolAudit::query()->create(['actor_id' => $actor?->id, 'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : 'system', 'subject_id' => $subject?->getKey(),
            'context' => ['ip' => $packed === false ? null : inet_ntop($packed), 'agent' => mb_substr((string) request()->userAgent(), 0, 255),
                'source' => app()->runningInConsole() ? 'system' : (request()->is('api/*') ? 'api' : 'web'), 'request_id' => (string) Str::uuid()],
            'metadata' => $metadata, 'context_expires_at' => now()->addDays(config()->integer('carpool.ip_retention_days'))]);
    }
}
