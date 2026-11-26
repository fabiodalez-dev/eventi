<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\Editorial\CampaignBuilder;
use App\Support\Sponsorship\SponsorableEventChoices;
use App\Support\Sponsorship\SponsorableEvents;
use Carbon\CarbonImmutable;

/**
 * «Nuova sponsorizzazione» nell'app (R-SP-03): i quattro passi del pannello
 * letti dal server a ogni passo. Le scelte (committenti, eventi
 * sponsorizzabili), quello che il pannello calcola mentre si compila (fuso,
 * fine dopo N mesi, prezzo dal listino, stima dello spazio, chi lo occupa,
 * anteprima di «tutti gli eventi», riepilogo) e il salvataggio vengono tutti
 * da `CampaignBuilder`, la stessa regola del pannello.
 */
final class CampaignWizardDesk
{
    public function __construct(private readonly CampaignBuilder $builder) {}

    /**
     * I committenti fra cui scegliere: i locali approvati o gli organizzatori
     * attivi (`all` = tutti, per il filtro dei locali di un committente
     * esterno e per le abilitazioni), con i contatti che compilano chi paga.
     *
     * @return list<array<string, mixed>>
     */
    public function sponsors(string $type, string $search, bool $all = false): array
    {
        $multipleCities = City::timezones()->count() > 1;

        if ($type === CampaignBuilder::SPONSOR_ORGANIZER) {
            return CampaignBuilder::organizers($search, null, ! $all)
                ->map(fn (Organizer $organizer): array => self::sponsorRow($organizer, (string) $organizer->name))->values()->all();
        }

        return CampaignBuilder::venues($search, null, ! $all)
            ->map(fn (Venue $venue): array => self::sponsorRow($venue, $multipleCities && $venue->city !== null ? $venue->name.' · '.$venue->city->name : (string) $venue->name))
            ->values()->all();
    }

    /**
     * Gli eventi sponsorizzabili fra cui scegliere, con la data e il
     * distintivo «già sponsorizzato»; `keep` tiene l'evento di una campagna
     * che si modifica anche se non lo è più.
     *
     * @param  array<string, mixed>  $state
     * @return array{data: list<array<string, mixed>>, count: int, ready: bool}
     */
    public function events(array $state, string $search, ?int $keep = null): array
    {
        $base = ($state['sponsor_type'] ?? null) === 'any'
            ? Event::query()
            : CampaignBuilder::eventsQuery($state['sponsor_type'] ?? null, $state['venue_id'] ?? null, $state['organizer_id'] ?? null, $state['venue_filter'] ?? null);

        if ($base === null) {
            return ['data' => [], 'count' => 0, 'ready' => false];
        }

        $rows = SponsorableEventChoices::rows(SponsorableEventChoices::sponsorable($base, $keep), $search);
        $sponsored = array_flip(SponsorableEvents::alreadySponsored(array_map('intval', $rows->modelKeys())));

        return [
            'data' => $rows->map(fn (Event $event): array => self::eventRow($event, isset($sponsored[(int) $event->getKey()])))->values()->all(),
            'count' => $rows->count(),
            'ready' => true,
        ];
    }

    /**
     * L'arrivo da «Sponsorizza questo evento» (`?event=ID` del pannello): il
     * committente è il locale, poi l'organizzatore, altrimenti esterno.
     *
     * @return array<string, mixed>
     */
    public function prefill(?Event $event): array
    {
        $type = match (true) {
            $event === null, $event->venue_id !== null => CampaignBuilder::SPONSOR_VENUE,
            $event->organizer_id !== null => CampaignBuilder::SPONSOR_ORGANIZER,
            default => CampaignBuilder::SPONSOR_EXTERNAL,
        };
        $sponsor = match (true) {
            $event === null => null,
            $type === CampaignBuilder::SPONSOR_VENUE => $event->venue,
            $type === CampaignBuilder::SPONSOR_ORGANIZER => $event->organizer,
            default => null,
        };
        $start = CarbonImmutable::now('UTC')->startOfHour();
        $tz = CampaignBuilder::timezone($event?->city_id);

        return [
            'sponsor_type' => $type,
            'venue_id' => $type === CampaignBuilder::SPONSOR_VENUE && $sponsor instanceof Venue ? (int) $sponsor->getKey() : null,
            'organizer_id' => $type === CampaignBuilder::SPONSOR_ORGANIZER && $sponsor instanceof Organizer ? (int) $sponsor->getKey() : null,
            'sponsor_label' => $sponsor?->name,
            'scope' => CampaignBuilder::SCOPE_EVENT,
            'event_id' => $event?->getKey(),
            'event' => $event === null ? null : self::eventRow(SponsorableEventChoices::find(Event::query(), $event->getKey()) ?? $event, false),
            'city_id' => $event?->city_id,
            'advertiser_name' => $sponsor?->name,
            'advertiser_email' => CampaignBuilder::sponsorEmail($sponsor),
            'advertiser_url' => $sponsor?->website ?: null,
            'starts_at' => PromotionPayload::instant($start, $tz),
            'months' => 1,
            'ends_at' => PromotionPayload::instant($start->addMonthNoOverflow(), $tz),
            'product' => CampaignBuilder::PRODUCT_PERIOD,
            'pacing' => 'even',
            'complimentary' => false,
            'status' => 'active',
            'priority' => 0,
            'weight' => 1,
        ];
    }

    /**
     * Quello che il pannello mostra mentre si compila, per lo stato
     * dell'app: fuso, fine calcolata dai mesi, prezzo dal listino, stima,
     * chi occupa già lo spazio, anteprima di «tutti gli eventi», riepilogo.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function preview(array $state): array
    {
        $type = $state['sponsor_type'] ?? null;
        $scope = CampaignBuilder::scopeOf($type, $state['scope'] ?? null);
        $sponsor = is_string($type) ? CampaignBuilder::sponsor($type, $state['venue_id'] ?? null, $state['organizer_id'] ?? null) : null;
        $cityId = CampaignBuilder::cityIdFor($scope, $state['event_id'] ?? null, $sponsor);
        $tz = CampaignBuilder::timezone($cityId);
        $start = CampaignBuilder::instantIn($state['starts_at'] ?? null, $tz);
        $end = CampaignBuilder::instantIn($state['ends_at'] ?? null, $tz);
        $months = filter_var($state['months'] ?? null, FILTER_VALIDATE_INT);
        $suggestedEnd = $start !== null && $months !== false ? CampaignBuilder::endAfterMonths($start, $months, $tz) : null;
        $end = $suggestedEnd ?? $end;
        $placement = $state['placement'] ?? null;
        $views = ($state['product'] ?? CampaignBuilder::PRODUCT_PERIOD) === CampaignBuilder::PRODUCT_VIEWS;
        $goal = $views && is_numeric($state['impressions_goal'] ?? null) ? (int) $state['impressions_goal'] : null;
        $estimate = $this->builder->estimate($cityId, $placement, $start, $end, $scope, $state['event_id'] ?? null);
        $price = CampaignBuilder::listPrice($placement, $months);
        $eventsQuery = $scope === CampaignBuilder::SCOPE_ALL ? CampaignBuilder::eventsQuery($type, $state['venue_id'] ?? null, $state['organizer_id'] ?? null) : null;
        $placementCase = SponsorshipPlacement::tryFrom(is_string($placement) ? $placement : '');

        return [
            'scope' => $scope,
            'city_id' => $cityId,
            'timezone' => $tz,
            'period_lead' => __('sponsorships.wizard.sections.period_lead', ['timezone' => $tz]),
            'ends_at' => $suggestedEnd === null ? null : PromotionPayload::instant($suggestedEnd, $tz),
            'period_only' => CampaignBuilder::periodOnlyMessage($placement),
            'price' => $price === null ? null : ['amount' => PromotionPayload::euros($price->amount_cents), 'label' => $price->formattedAmount()],
            'price_hint' => CampaignBuilder::priceHint($price),
            'estimate' => $estimate === null ? null : [
                'views' => $estimate['views'],
                'days' => $estimate['days'],
                'provisional' => $estimate['provisional'],
                'heading' => CampaignBuilder::estimateHeading($estimate),
                'description' => CampaignBuilder::estimateDescription($estimate, $goal),
                'status' => CampaignBuilder::estimateStatus($estimate, $goal, $views),
            ],
            'occupants' => array_map(fn (array $unit): array => [
                'key' => $unit['key'],
                'name' => $unit['name'],
                'kind' => $unit['kind'],
                'starts_at' => PromotionPayload::instant($unit['starts_at'], $tz),
                'ends_at' => PromotionPayload::instant($unit['ends_at'], $tz),
                'goal' => $unit['goal'],
                'events' => $unit['events'],
            ], $this->builder->occupants($cityId, $placement, $start, $end)),
            'all_events' => $scope !== CampaignBuilder::SCOPE_ALL ? null : [
                'sponsor' => $sponsor?->name,
                'count' => $eventsQuery === null ? 0 : SponsorableEventChoices::count($eventsQuery),
                'events' => $eventsQuery === null ? [] : array_map(fn (Event $event): array => self::eventRow($event, false), SponsorableEventChoices::preview($eventsQuery, CampaignBuilder::PREVIEW_LIMIT)),
            ],
            'placement_lead' => $placementCase === null ? null : __('promotions.formats.'.$placementCase->value),
        ];
    }

    /**
     * Crea la campagna o l'abilitazione dopo aver controllato tutti e
     * quattro i passi.
     *
     * @param  array<string, mixed>  $input
     * @return array{record: Sponsorship|SponsorshipGrant, message: string}
     */
    public function create(array $input, User $user): array
    {
        $data = $this->builder->validate($input, 4);
        $record = CampaignBuilder::save($data, (int) $user->getKey(), '');

        if ($record instanceof SponsorshipGrant) {
            $count = $record->sponsorships()->count();

            return ['record' => $record, 'message' => trans_choice('sponsorships.wizard.created_grant', $count, ['count' => $count])];
        }

        return ['record' => $record, 'message' => __('sponsorships.wizard.created')];
    }

    /** @return array<string, mixed> */
    private static function sponsorRow(Venue|Organizer $sponsor, string $label): array
    {
        return [
            'id' => (int) $sponsor->getKey(),
            'label' => $label,
            'name' => (string) $sponsor->name,
            'city_id' => (int) $sponsor->city_id,
            'email' => CampaignBuilder::sponsorEmail($sponsor),
            'website' => $sponsor->website ?: null,
        ];
    }

    /** @return array<string, mixed> */
    public static function eventRow(Event $event, bool $alreadySponsored): array
    {
        $tz = (string) ($event->city?->timezone ?: config()->string('app.timezone'));
        $upcoming = (int) $event->getAttribute('upcoming_count');

        return [
            ...(PromotionPayload::event($event, $tz) ?? []),
            'city_id' => (int) $event->city_id,
            'timezone' => $tz,
            'more_dates' => max(0, $upcoming - 1),
            'already_sponsored' => $alreadySponsored,
        ];
    }
}
