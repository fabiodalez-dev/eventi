<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AttendanceMode;
use App\Enums\SeoIndexing;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\Venue;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

final class EditorialFields
{
    public static function content(bool $event = false, bool $place = false, bool $taxonomy = false): Section
    {
        $fields = [Textarea::make('content_details.introduction')->label(__('seo.fields.introduction'))->maxLength(5000)->rows(3)];
        if ($taxonomy) {
            $fields[] = Repeater::make('content_details.city_introductions')->label(__('seo.city_introductions'))->defaultItems(0)->columns(1)->schema([
                Select::make('city_id')->label(__('seo.search_console.city'))->options(City::pluck('name', 'id'))->required()->exists('cities', 'id')->distinct(),
                Textarea::make('text')->label(__('seo.fields.introduction'))->required()->maxLength(5000),
            ]);
        }
        if ($event || $place) {
            $fields[] = Select::make('content_details.parking_type')->label(__('seo.parking_type'))
                ->options(['free' => __('seo.parking_free'), 'paid' => __('seo.parking_paid'), 'none' => __('seo.parking_none')])->placeholder(__('seo.unspecified'));
            foreach (['parking_notes', 'transit_notes', 'entrance_notes', 'accessibility_notes'] as $key) {
                $fields[] = Textarea::make('content_details.'.$key)->label(__('seo.fields.'.$key))->maxLength(2000)->rows(2);
            }
            $fields[] = Select::make('content_details.accessibility')->label(__('seo.fields.accessibility'))
                ->options(['yes' => __('seo.yes'), 'no' => __('seo.no')])->placeholder(__('seo.unspecified'));
        }
        if ($event) {
            $fields[] = Select::make('content_details.organizer_venue_id')->label(__('seo.registered_organizer'))
                ->options(Venue::query()->approved()->orderBy('name')->pluck('name', 'id'))->searchable()
                ->rules([Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)]);
            $fields[] = TextInput::make('content_details.minimum_age')->label(__('seo.minimum_age'))->integer()->minValue(0)->maxValue(120);
            $fields[] = Select::make('content_details.attendance_mode')->label(__('seo.mode'))->options([
                AttendanceMode::Offline->value => __('seo.offline'),
                AttendanceMode::Online->value => __('seo.online'),
                AttendanceMode::Mixed->value => __('seo.mixed'),
            ])->default('offline')->live();
            $fields[] = TextInput::make('content_details.online_url')->label(__('seo.online_url'))->url()->maxLength(2048)
                ->helperText(__('seo.online_help'))->required(fn (Get $get): bool => in_array($get('content_details.attendance_mode'), ['online', 'mixed'], true));
            $fields[] = Select::make('content_details.organizer_type')->label(__('seo.organizer_type'))
                ->options(['Organization' => __('seo.organization'), 'Person' => __('seo.person')])->default('Organization');
            if (Filament::getCurrentPanel()?->getId() === 'venue') {
                $fields[] = TextInput::make('organizer_name')->label(__('seo.organizer_name'))->maxLength(180);
                $fields[] = TextInput::make('organizer_url')->label(__('seo.organizer_url'))->url()->maxLength(2048);
            }
            foreach (['membership_notes', 'mandatory_costs', 'weather_policy', 'minors_policy', 'cancellation_policy', 'refund_policy', 'public_contact', 'poster_alt', 'poster_caption', 'poster_credit'] as $key) {
                $fields[] = Textarea::make('content_details.'.$key)->label(__('seo.fields.'.$key))->maxLength(2000)->rows(2);
            }
            $fields[] = Repeater::make('content_details.agenda')->label(__('seo.agenda'))->defaultItems(0)->maxItems(50)
                ->columns(1)->schema([
                    TextInput::make('title')->label(__('seo.fields.title'))->required()->maxLength(180),
                    TextInput::make('when')->label(__('seo.fields.when'))->helperText(__('seo.agenda_help'))->required()->maxLength(120),
                    DateTimePicker::make('starts_at')->label(__('seo.fields.starts_at'))->seconds(false),
                    DateTimePicker::make('ends_at')->label(__('seo.fields.ends_at'))->seconds(false)->after('starts_at'),
                    Select::make('occurrence_id')->label(__('seo.fields.occurrence'))->placeholder(__('seo.all_dates'))
                        ->options(fn (?Event $record): array => $record?->occurrences()->get()->mapWithKeys(fn ($date): array => [$date->id => $date->starts_at->copy()->timezone($record->city->timezone)->format('d/m/Y H:i')])->all() ?? [])
                        ->rules(fn (?Event $record): array => [Rule::exists('event_occurrences', 'id')->where('event_id', $record?->id)]),
                    TextInput::make('speaker')->label(__('seo.fields.speaker'))->maxLength(180),
                    Textarea::make('description')->label(__('seo.fields.description'))->maxLength(5000),
                ])->collapsible()->itemLabel(fn (array $state): ?string => $state['title'] ?? null);
        }
        $fields[] = Repeater::make('content_details.faqs')->label(__('seo.faqs'))->defaultItems(0)->maxItems(30)
            ->columns(1)->schema([
                TextInput::make('question')->label(__('seo.question'))->required()->maxLength(250),
                Textarea::make('answer')->label(__('seo.answer'))->required()->maxLength(5000),
            ])->collapsible()->itemLabel(fn (array $state): ?string => $state['question'] ?? null);

        return Section::make(__('seo.information'))->description(__('seo.information_help'))->schema($fields)->columns(1)->collapsed();
    }

    public static function seo(): Section
    {
        return Section::make(__('seo.settings'))->description(__('seo.settings_help'))->columns(1)->collapsed()->schema([
            TextInput::make('seo.title')->label(__('seo.fields.title'))->maxLength(180)->live(onBlur: true),
            Textarea::make('seo.description')->label(__('seo.fields.description'))->maxLength(400)->rows(3)->live(onBlur: true),
            TextInput::make('seo.image')->label(__('seo.fields.image'))->url()->maxLength(2048),
            Text::make(fn (Get $get): HtmlString => new HtmlString(
                '<div class="space-y-2"><strong>'.e($get('seo.title') ?: $get('title') ?: $get('name') ?: __('seo.automatic')).'</strong><p>'.e($get('seo.description') ?: $get('short_description') ?: __('seo.automatic')).'</p></div>'
            )),
            Select::make('seo.indexing')->label(__('seo.indexing'))->options([
                SeoIndexing::Automatic->value => __('seo.automatic'),
                SeoIndexing::Excluded->value => __('seo.excluded'),
            ])->default('automatic')->visible(fn (): bool => self::admin())->dehydrated(fn (): bool => self::admin()),
            Toggle::make('is_demo')->label(__('seo.demo'))->helperText(__('seo.demo_help'))
                ->visible(fn (): bool => self::admin())->dehydrated(fn (): bool => self::admin()),
        ]);
    }

    private static function admin(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }
}
