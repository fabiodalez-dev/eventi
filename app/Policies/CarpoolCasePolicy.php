<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarpoolCase;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;

final class CarpoolCasePolicy
{
    public function view(User $user, CarpoolCase $record): bool
    {
        return app(CommunitySafety::class)->involved($user, $record);
    }
}
