<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoogleCalendarConnection;
use App\Models\User;

final class GoogleCalendarConnectionPolicy
{
    public function view(User $user, GoogleCalendarConnection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function update(User $user, GoogleCalendarConnection $connection): bool
    {
        return $this->view($user, $connection);
    }

    public function delete(User $user, GoogleCalendarConnection $connection): bool
    {
        return $this->view($user, $connection);
    }
}
