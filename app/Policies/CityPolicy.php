<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\City;
use App\Models\User;

class CityPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, City $city): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageCities->value);
    }

    public function update(User $user, City $city): bool
    {
        return $user->can(Permission::ManageCities->value);
    }

    public function delete(User $user, City $city): bool
    {
        return $user->can(Permission::ManageCities->value);
    }
}
