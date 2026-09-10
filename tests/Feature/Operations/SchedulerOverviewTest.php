<?php

use App\Filament\Admin\Pages\ScheduledOperations;
use App\Models\User;
use App\Services\Operations\SchedulerOverview;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Process;

it('documents the live scheduler and both master cron lines', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    Process::fake(['crontab -l' => Process::result(output: '* * * * * cd '.base_path().' && php artisan schedule:run')]);
    $this->actingAs($user)->get(ScheduledOperations::getUrl())->assertOk()
        ->assertSee('social:publish-due')->assertSee('notifications:send')->assertSee('backup:run')
        ->assertSee('queue:work')->assertSee('Nessun collegamento social configurato');
    expect(app(SchedulerOverview::class)->cron()['scheduler'])->toContain('flock -n', 'schedule:run');
});

it('denies the cron overview to regular users', function (): void {
    $this->actingAs(User::factory()->create())->get(ScheduledOperations::getUrl())->assertForbidden();
});
