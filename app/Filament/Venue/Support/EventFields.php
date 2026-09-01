<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Enums\PriceType;
use App\Filament\Support\ExternalLinksField;
use App\Filament\Support\FactsField;
use App\Filament\Support\ImageUpload;
use App\Filament\Support\TicketTiersField;
use App\Models\Category;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * I campi dell'evento come li vede un gestore: gli stessi nel wizard e nella
 * scheda di modifica, definiti una volta sola.
 *
 * Due criteri li governano, e sono entrambi conseguenza di §10.2 — *90
 * secondi, in piedi, con il telefono in mano*:
 *
 * 1. **Meno campi possibile.** Tutto ciò che la redazione può correggere dopo
 *    (sottotitolo, riassunto, organizzatore, punteggio, verifica, evidenza)
 *    non compare: sono le colonne che distinguono `/admin` da `/gestione`.
 * 2. **La tastiera giusta al primo colpo.** Un prezzo apre il tastierino, un
 *    indirizzo web la tastiera con la barra, una data il selettore del
 *    sistema operativo (`native`, che sul telefono è la rotellina nativa e
 *    non un calendario disegnato).
 *
 * Gli orari si scrivono e si leggono **nell'ora della città** e si salvano in
 * UTC: la conversione la fa `->timezone()`, che senza sarebbe l'ora del
 * server (§8.1).
 */
final class EventFields
{
    public static function poster(): Component
    {
        return ImageUpload::make('poster_media')
            ->label(__('manage.fields.poster'))
            ->helperText(__('manage.hints.poster'))
            ->collection('poster')
            ->imageEditor();
    }

    public static function title(): Component
    {
        return TextInput::make('title')
            ->label(__('manage.fields.title'))
            ->placeholder(__('manage.placeholders.title'))
            ->required()
            ->maxLength(255);
    }

    public static function startsAt(): Component
    {
        return DateTimePicker::make('starts_at')
            ->label(__('manage.fields.starts_at'))
            ->seconds(false)
            ->required()
            ->timezone(CurrentVenue::timezone());
    }

    public static function endsAt(): Component
    {
        return DateTimePicker::make('ends_at')
            ->label(__('manage.fields.ends_at'))
            ->helperText(__('manage.hints.ends_at'))
            ->seconds(false)
            ->after('starts_at')
            ->timezone(CurrentVenue::timezone());
    }

    public static function category(): Component
    {
        return Select::make('category_id')
            ->label(__('manage.fields.category'))
            ->options(self::categories(...))
            ->required();
    }

    public static function tags(): Component
    {
        return Select::make('tags')
            ->label(__('manage.fields.tags'))
            ->helperText(__('manage.hints.tags'))
            ->relationship('tags', 'name')
            ->multiple()
            ->preload();
    }

    public static function priceType(): Component
    {
        return ToggleButtons::make('price_type')
            ->label(__('manage.fields.price_type'))
            ->options(PriceType::options())
            ->required()
            ->default(PriceType::Free->value)
            ->live();
    }

    public static function priceMin(): Component
    {
        return TextInput::make('price_min')
            ->label(__('manage.fields.price_min'))
            ->numeric()
            ->minValue(0)
            ->inputMode('decimal')
            ->visible(fn (Get $get): bool => self::hasAmount($get('price_type')));
    }

    public static function priceMax(): Component
    {
        return TextInput::make('price_max')
            ->label(__('manage.fields.price_max'))
            ->helperText(__('manage.hints.price_max'))
            ->numeric()
            ->minValue(0)
            ->inputMode('decimal')
            ->visible(fn (Get $get): bool => self::hasAmount($get('price_type')));
    }

    public static function description(): Component
    {
        return Textarea::make('description')
            ->label(__('manage.fields.description'))
            ->placeholder(__('manage.placeholders.description'))
            ->rows(5);
    }

    public static function ticketUrl(): Component
    {
        return TextInput::make('ticket_url')
            ->label(__('manage.fields.ticket_url'))
            ->helperText(__('manage.hints.ticket_url'))
            ->placeholder(__('manage.placeholders.ticket_url'))
            ->url()
            ->inputMode('url')
            ->maxLength(255);
    }

    public static function bookingUrl(): Component
    {
        return TextInput::make('booking_url')
            ->label(__('manage.fields.booking_url'))
            ->helperText(__('manage.hints.booking_url'))
            ->placeholder(__('manage.placeholders.url'))
            ->url()
            ->inputMode('url')
            ->maxLength(255);
    }

    /**
     * I link esterni di §7.6: l'evento su Facebook, il sito della band,
     * l'articolo del giornale locale.
     *
     * Nel wizard è l'ultima cosa dell'ultimo passo e **parte chiuso**
     * (`defaultItems(0)`): chi vuole i 90 secondi di §10.2 preme "Pubblica" e
     * non lo vede nemmeno, chi ha il link nella clipboard lo incolla.
     */
    public static function externalLinks(): Component
    {
        return ExternalLinksField::make('manage');
    }

    /**
     * Le fasce di prezzo dell'evento, valide per tutte le sue date.
     *
     * È la risposta che un gestore vuole poter dare senza telefonare a
     * nessuno: *il posto in piedi è finito, quello a sedere no*. Fino a oggi
     * poteva solo segnare esaurita l'intera serata, cioè dire una cosa più
     * grave del vero e perdere chi avrebbe comprato il posto rimasto.
     *
     * Parte chiusa, come i link esterni: chi vuole i 90 secondi di §10.2 non
     * la apre nemmeno.
     */
    public static function ticketTiers(): Component
    {
        return TicketTiersField::make('manage');
    }

    /**
     * La scheda tecnica: le cose che al bancone gli chiedono ogni sera — a che
     * ora si apre, quanto dura, da che età si entra.
     */
    public static function facts(): Component
    {
        return FactsField::make('manage', 'facts');
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        /** @var array<int, string> $options */
        $options = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return $options;
    }

    private static function hasAmount(mixed $priceType): bool
    {
        $type = $priceType instanceof PriceType ? $priceType : PriceType::tryFrom((string) $priceType);

        return $type === PriceType::Ticket || $type === PriceType::Membership;
    }
}
