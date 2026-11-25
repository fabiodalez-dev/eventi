<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Enums\SponsorshipPlacement;
use App\Models\Organizer;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\SponsorshipAnalyticsReport;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Le sponsorizzazioni del soggetto (P6, W-SP-*, W-ST-08/09).
 *
 * Il soggetto l'ha già controllato `ResolveManagementSubject` (la barriera
 * dei pannelli): le pagine «Sponsorizzazioni» e «Statistiche
 * sponsorizzazioni» si aprono a ogni membro, locale o organizzazione, come
 * sul sito. Le rotte degli ordini (invio, riprova, annulla, ritira) chiedono
 * in più `SponsorshipOrderPolicy::purchase`, cioè il titolare: un
 * collaboratore guarda, mette in evidenza un evento su un periodo già pagato
 * e ferma una campagna (decisione 7), ma non spende.
 */
final class SponsorshipDeskRequest extends FormRequest
{
    /** Le rotte che spendono (o ritirano) a nome del committente. */
    private const PURCHASE_ROUTES = ['orders.store', 'orders.retry', 'orders.cancel', 'orders.withdraw'];

    public function authorize(): bool
    {
        if ($this->routeIs(...array_map(fn (string $name): string => '*.sponsorships.'.$name, self::PURCHASE_ROUTES))) {
            Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        }

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('*.sponsorships.index') => [
                'campaigns_page' => ['sometimes', 'integer', 'min:1'],
            ],
            $this->routeIs('*.sponsorships.events') => [
                'q' => ['sometimes', 'nullable', 'string', 'max:80'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
                'grant' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ],
            $this->routeIs('*.sponsorships.campaigns.index') => [
                'page' => ['sometimes', 'integer', 'min:1'],
            ],
            $this->routeIs('*.sponsorships.orders.store') => [
                'scope' => ['required', 'string', Rule::in(['event', 'all'])],
                'event_id' => ['nullable', 'required_if:scope,event', 'integer', 'min:1'],
                'placement' => ['required', 'string', Rule::in(SponsorshipPlacement::values())],
                'duration' => ['required', 'string', 'max:40'],
                'note' => ['nullable', 'string', 'max:1000'],
            ],
            $this->routeIs('*.sponsorships.orders.reconcile') => [
                'cancelled' => ['sometimes', 'boolean'],
            ],
            $this->routeIs('*.sponsorships.grants.events') => [
                'event_id' => ['required', 'integer', 'min:1'],
            ],
            $this->routeIs('*.sponsorships.analytics', '*.sponsorships.analytics.clicks') => [
                'days' => ['sometimes', 'string', Rule::in(SponsorshipAnalyticsReport::PERIODS)],
                'from' => ['nullable', 'required_if:days,custom', 'date'],
                'to' => ['nullable', 'required_if:days,custom', 'date', 'after_or_equal:from'],
                'campaign' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ],
            $this->routeIs('*.sponsorships.analytics.campaigns') => [
                'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'scope' => __('promotions.page.step_what'),
            'event_id' => __('promotions.event'),
            'placement' => __('promotions.page.step_where'),
            'duration' => __('promotions.page.step_time'),
            'note' => __('promotions.page.note_label'),
            'days' => __('sponsorships.analytics.period'),
            'from' => __('sponsorships.analytics.from'),
            'to' => __('sponsorships.analytics.to'),
            'campaign' => __('sponsorships.analytics.campaign'),
        ];
    }

    public function subject(): ManagementSubject
    {
        return ManagementSubject::require();
    }

    public function sponsor(): Venue|Organizer
    {
        $sponsor = $this->subject()->model;

        return $sponsor->relationLoaded('city') ? $sponsor : $sponsor->load('city');
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** Un id della rotta (`order`, `grant`, `campaign`), già vincolato ai numeri. */
    public function routeId(string $name): int
    {
        $id = $this->route($name);
        abort_unless(is_numeric($id) && (int) $id > 0, 404);

        return (int) $id;
    }
}
