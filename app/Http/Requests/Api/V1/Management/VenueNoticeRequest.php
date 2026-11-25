<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Enums\VenueNoticeKind;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Avvisa chi ti segue» dall'app: gli stessi campi del modale della pagina
 * «Il tuo locale» (tipo, testo fino a 500 caratteri, «valido fino al» da oggi
 * in poi), lo stesso permesso (`VenuePolicy::update`). Il tetto di un avviso
 * ogni sette giorni lo controlla `PublishVenueNotice`.
 */
final class VenueNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->venue()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = CarbonImmutable::now($this->venue()->city->timezone ?? config()->string('app.timezone'))->toDateString();

        return [
            'kind' => ['required', Rule::enum(VenueNoticeKind::class)],
            'body' => ['required', 'string', 'max:500'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
        ];
    }

    public function attributes(): array
    {
        return [
            'kind' => __('manage.notices.kind'),
            'body' => __('manage.notices.body'),
            'until' => __('manage.notices.until'),
        ];
    }

    public function kind(): VenueNoticeKind
    {
        return VenueNoticeKind::from((string) $this->validated('kind'));
    }

    public function until(): ?CarbonImmutable
    {
        $until = $this->validated('until');

        return is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null;
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }
}
