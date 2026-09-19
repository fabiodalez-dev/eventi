<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [PermissionEnum::ManageCommunity, PermissionEnum::ManageCarpool, PermissionEnum::ManageCommunityCases,
            PermissionEnum::ReadCommunityMessages, PermissionEnum::ReadCommunitySecurity, PermissionEnum::ExportCommunityEvidence, PermissionEnum::ManageCommunityRetention];
        foreach ($permissions as $permission) {
            $row = Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
            foreach ([UserRole::Admin, UserRole::SuperAdmin] as $role) {
                Role::where('name', $role->value)->first()?->givePermissionTo($row);
            }
            if (in_array($permission, array_slice($permissions, 0, 3), true)) {
                Role::where('name', UserRole::Moderator->value)->first()?->givePermissionTo($row);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
