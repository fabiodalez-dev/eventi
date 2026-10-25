<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\CatalogReviews;

use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Filament\Organizer\Resources\CatalogReviews\Pages\ListCatalogReviews;
use App\Filament\Shared\Reviews\ReceivedReviewsTable;
use App\Models\CatalogReview;
use App\Models\Organizer;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Le recensioni pubblicate del proprio profilo di organizzatore, con la risposta pubblica.
 *
 * Come per i commenti, la tenancy automatica non basta: una recensione arriva
 * al soggetto con una relazione polimorfica, e senza il filtro esplicito qui
 * sotto si vedrebbero quelle di tutti. Vedi `ReceivedReviewsTable`.
 */
class CatalogReviewResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = CatalogReview::class;

    protected static ?string $slug = 'recensioni';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('manage.navigation.community');
    }

    public static function getNavigationLabel(): string
    {
        return __('reviews.reply.panel');
    }

    public static function getModelLabel(): string
    {
        return __('reviews.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviews.reply.panel');
    }

    /** Il titolo della pagina e la briciola restano come sono scritti, senza maiuscole a ogni parola. */
    public static function getTitleCasePluralModelLabel(): string
    {
        return __('reviews.reply.panel');
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @param  Builder<CatalogReview>  $query
     * @return Builder<CatalogReview>
     */
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        $tenant ??= Filament::getTenant();
        abort_unless($tenant instanceof Organizer, 403);

        return ReceivedReviewsTable::scope($query, $tenant);
    }

    public static function table(Table $table): Table
    {
        return ReceivedReviewsTable::table($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogReviews::route('/')];
    }
}
