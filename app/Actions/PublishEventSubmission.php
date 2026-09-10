<?php

namespace App\Actions;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\Permission;
use App\Enums\PriceType;
use App\Enums\SubmissionStatus;
use App\Enums\VenueStatus;
use App\Enums\VerificationStatus;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PublishEventSubmission
{
    /** @param array<string, mixed> $data */
    public function handle(EventSubmission $submission, User $reviewer, array $data): Event
    {
        Gate::forUser($reviewer)->authorize('update', $submission);
        Gate::forUser($reviewer)->authorize('create', Event::class);
        abort_unless($reviewer->can(Permission::PublishEvents->value), 403);

        return DB::transaction(function () use ($submission, $reviewer, $data): Event {
            $submission = EventSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->event_id !== null) {
                return $submission->event()->firstOrFail();
            }
            if ($submission->status !== SubmissionStatus::Pending) {
                throw ValidationException::withMessages(['data.title' => 'Questa proposta è già stata esaminata.']);
            }
            $values = Validator::make($data, [
                'title' => ['required', 'string', 'max:255'],
                'raw_text' => ['nullable', 'string', 'max:50000'],
                'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('city_id', $submission->city_id)->where('status', VenueStatus::Approved->value)->whereNull('deleted_at')],
                'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
                'starts_at_hint' => ['required', 'date'],
                'ends_at' => ['nullable', 'date', 'after:starts_at_hint'],
                'price_type' => ['required', Rule::enum(PriceType::class)],
                'price_min' => ['nullable', 'numeric', 'min:0'],
            ])->validate();
            $event = Event::create([
                'city_id' => $submission->city_id, 'venue_id' => $values['venue_id'],
                'category_id' => $values['category_id'], 'created_by' => $reviewer->id,
                'title' => $values['title'], 'description' => $values['raw_text'] ?? null,
                'source' => EventSource::Submission, 'source_ref' => (string) $submission->id,
                'status' => EventStatus::Draft, 'verification_status' => VerificationStatus::Unverified,
                'price_type' => $values['price_type'], 'price_min' => $values['price_min'] ?? null, 'currency' => 'EUR',
            ]);
            $event->occurrences()->create([
                'starts_at' => CarbonImmutable::parse($values['starts_at_hint'], 'UTC'),
                'ends_at' => filled($values['ends_at'] ?? null) ? CarbonImmutable::parse($values['ends_at'], 'UTC') : null,
                'status' => OccurrenceStatus::Scheduled, 'is_all_day' => false,
            ]);
            app(PublishEventAction::class)->publish($event);
            $submission->update(['event_id' => $event->id, 'status' => SubmissionStatus::Approved,
                'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);

            return $event;
        });
    }
}
