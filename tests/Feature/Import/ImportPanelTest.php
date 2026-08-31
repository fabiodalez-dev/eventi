<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\ImportSources\Pages\ListImportSources;
use App\Filament\Support\ImportPreviewRows;
use App\Jobs\Import\ImportSourceJob;
use App\Models\Event;
use App\Models\ImportSource;
use App\Models\User;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\IcsFixtures;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    (new RolesAndPermissionsSeeder)->run();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($this->admin);

    $this->source = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
    ]);

    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
});

it('mostra l anteprima senza scrivere niente', function (): void {
    IcsFixtures::fake('internal-entries');

    $preview = app(ImportRunner::class)->preview($this->source);
    $html = view('filament.import.preview', [
        'rows' => ImportPreviewRows::make($preview, $this->city->timezone),
        'error' => null,
    ])->render();

    expect($html)->toContain(e("Concerto del quartetto d'archi"))
        // Le voci interne non arrivano nemmeno all'anteprima.
        ->and($html)->not->toContain('Riunione staff')
        ->and($html)->toContain('05/09/2026 21:30')
        ->and(Event::query()->count())->toBe(0);
});

it('racconta il guasto nell anteprima invece di lasciare la pagina vuota', function (): void {
    IcsFixtures::fake('not-a-calendar');

    $html = view('filament.import.preview', [
        'rows' => [],
        'error' => __('import.errors.not_a_calendar'),
    ])->render();

    expect($html)->toContain(__('import.errors.not_a_calendar'));
});

it('accoda l esecuzione a mano dalla tabella', function (): void {
    Queue::fake();

    Livewire::test(ListImportSources::class)
        ->callTableAction('run', $this->source)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(
        ImportSourceJob::class,
        fn (ImportSourceJob $job): bool => $job->sourceId === (int) $this->source->getKey(),
    );
});

it('non offre esecuzione ne anteprima a una sorgente senza driver', function (): void {
    $manual = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'type' => ImportSourceType::Manual,
    ]);

    Livewire::test(ListImportSources::class)
        ->assertTableActionHidden('run', $manual)
        ->assertTableActionHidden('preview', $manual)
        ->assertTableActionVisible('run', $this->source);
});

it('apre l elenco delle sorgenti senza chiavi di traduzione grezze', function (): void {
    $this->source->update(['last_status' => ImportRunStatus::Partial->value]);

    $response = $this->get(ImportSourceResource::getUrl('index'));

    $response->assertOk();

    expect($response->getContent())->toContain('Riuscito in parte')
        ->and($response->getContent())->not->toContain('enums.import_run_status');
});
