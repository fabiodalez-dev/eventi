<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Exceptions\ImportException;
use App\Jobs\Import\ImportSourceJob;
use App\Models\Event;
use App\Models\ImportSource;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IcsFixtures;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
});

function importSource(array $attributes = []): ImportSource
{
    return ImportSource::factory()->create([
        'city_id' => test()->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => test()->category->getKey(),
        ...$attributes,
    ]);
}

it('accoda un lavoro per sorgente attiva', function (): void {
    Queue::fake();

    $prima = importSource();
    $seconda = importSource();
    importSource(['is_active' => false]);
    importSource(['type' => ImportSourceType::Manual]);

    $this->artisan('import:run')->assertSuccessful();

    // Un lavoro per sorgente: e' cosi che una irraggiungibile non ferma le
    // altre. La sorgente spenta e quella senza driver restano fuori.
    Queue::assertPushed(ImportSourceJob::class, 2);
    Queue::assertPushed(fn (ImportSourceJob $job): bool => $job->sourceId === (int) $prima->getKey());
    Queue::assertPushed(fn (ImportSourceJob $job): bool => $job->sourceId === (int) $seconda->getKey());
});

it('accoda la sola sorgente richiesta', function (): void {
    Queue::fake();

    $prima = importSource();
    importSource();

    $this->artisan('import:run', ['--source' => $prima->getKey()])->assertSuccessful();

    Queue::assertPushed(ImportSourceJob::class, 1);
});

it('esegue e riferisce con --sync', function (): void {
    importSource();
    IcsFixtures::fake('internal-entries');

    $this->artisan('import:run', ['--sync' => true])
        ->expectsOutputToContain(__('console.import_run.done', [
            'sources' => 1,
            'created' => 2,
            'updated' => 0,
            'unchanged' => 0,
            'excluded' => 5,
            'cancelled' => 0,
            'errors' => 0,
        ]))
        ->assertSuccessful();

    expect(Event::query()->count())->toBe(2);
});

it('non interrompe le altre sorgenti quando una fallisce', function (): void {
    $rotta = importSource(['url' => 'https://irraggiungibile.test/eventi.ics']);
    $buona = importSource();

    IcsFixtures::fake('three-date-forms');

    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();

    // La prima sorgente non ha uno stub: la richiesta non parte affatto.
    expect($rotta->fresh()?->last_status)->toBe(ImportRunStatus::Failed->value)
        ->and($buona->fresh()?->last_status)->toBe(ImportRunStatus::Success->value)
        ->and(Event::query()->count())->toBe(3);
});

it('dice quando non c e nulla da eseguire', function (): void {
    Queue::fake();

    $this->artisan('import:run')
        ->expectsOutput(__('console.import_run.empty'))
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('il lavoro esegue l import della propria sorgente', function (): void {
    $source = importSource();
    IcsFixtures::fake('three-date-forms');

    (new ImportSourceJob((int) $source->getKey()))->handle(app(ImportRunner::class));

    expect(Event::query()->count())->toBe(3)
        ->and($source->fresh()?->last_status)->toBe(ImportRunStatus::Success->value);
});

it('il lavoro non tocca una sorgente spenta nel frattempo', function (): void {
    $source = importSource(['is_active' => false]);
    IcsFixtures::fake('three-date-forms');

    (new ImportSourceJob((int) $source->getKey()))->handle(app(ImportRunner::class));

    expect(Event::query()->count())->toBe(0)
        ->and($source->fresh()?->last_run_at)->toBeNull();
});

it('il lavoro sopravvive a una sorgente cancellata', function (): void {
    $source = importSource();
    $id = (int) $source->getKey();
    $source->delete();

    (new ImportSourceJob($id))->handle(app(ImportRunner::class));

    expect(Event::query()->count())->toBe(0);
});

it('il lavoro rilancia il guasto perche la coda possa riprovare', function (): void {
    $source = importSource();
    IcsFixtures::fakeFailure(new ConnectionException('Connection timed out'));

    $job = new ImportSourceJob((int) $source->getKey());

    expect(fn () => $job->handle(app(ImportRunner::class)))
        ->toThrow(ImportException::class);

    expect($job->tries)->toBe(config()->integer('import.job.tries'))
        ->and($job->timeout)->toBe(config()->integer('import.job.timeout'))
        ->and($job->backoff())->toBe(config()->array('import.job.backoff'))
        ->and($job->uniqueId())->toBe((string) $source->getKey())
        // Il guasto e' gia scritto sulla sorgente: la dashboard di §14.5 lo
        // vede anche se il lavoro finisce fra i falliti.
        ->and($source->fresh()?->last_status)->toBe(ImportRunStatus::Failed->value);
});

it('e schedulato ogni ora', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'import:run'));

    expect($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('0 * * * *');
});
