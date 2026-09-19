<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CatalogReviews;

use App\Enums\CatalogReviewStatus;
use App\Filament\Admin\Resources\CatalogReviews\Pages\ListCatalogReviews;
use App\Models\CatalogReview;
use App\Models\User;
use App\Services\Reviews\CatalogReviews;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CatalogReviewResource extends Resource
{
    protected static ?string $model = CatalogReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function getModelLabel(): string
    {
        return __('reviews.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviews.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CatalogReview::query()->where('status', CatalogReviewStatus::Pending)->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reviewable.name')->label(__('reviews.subject'))->wrap(),
            TextColumn::make('reviewable_type')->label(__('reviews.type'))->formatStateUsing(fn (string $state): string => __('reviews.types.'.$state)),
            TextColumn::make('user.name')->label(__('reviews.user'))->searchable(),
            TextColumn::make('rating')->label(__('reviews.rating'))->formatStateUsing(fn (?int $state): string => $state === null ? '—' : str_repeat('★', $state).str_repeat('☆', 5 - $state))->color('warning'),
            TextColumn::make('body')->label(__('reviews.body'))->wrap()->limit(120),
            TextColumn::make('status')->label(__('reviews.status'))->badge()->formatStateUsing(fn (CatalogReviewStatus $state): string => __('reviews.'.$state->value)),
            TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('moderator.name')->label(__('admin.fields.reviewed_by')),
        ])->defaultSort('updated_at', 'desc')->filters([
            SelectFilter::make('reviewable_type')->label(__('reviews.type'))->options(['venue' => __('reviews.types.venue'), 'organizer' => __('reviews.types.organizer')]),
            SelectFilter::make('status')->label(__('reviews.status'))->options(collect(CatalogReviewStatus::cases())->mapWithKeys(fn (CatalogReviewStatus $state): array => [$state->value => __('reviews.'.$state->value)])->all()),
        ])->recordActions([self::moderationAction('approved'), self::moderationAction('rejected')]);
    }

    private static function moderationAction(string $status): Action
    {
        return Action::make($status)->label(__('reviews.'.($status === 'approved' ? 'approve' : 'reject')))
            ->color($status === 'approved' ? 'success' : 'danger')
            ->authorize('update')->schema([
                Textarea::make('review_body')->label(__('reviews.body'))->default(fn (CatalogReview $record): string => $record->body ?? '')->disabled()->dehydrated(false)->rows(8),
                Hidden::make('revision')->default(fn (CatalogReview $record): int => $record->revision)->required(),
                Textarea::make('note')->label(__('reviews.note'))->minLength(5)->maxLength(1000)->required($status === 'rejected'),
            ])->action(function (CatalogReview $record, array $data) use ($status): void {
                $admin = auth()->user();
                abort_unless($admin instanceof User, 403);
                app(CatalogReviews::class)->moderate($record, $admin, $status, (int) $data['revision'], $data['note'] ?? null);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogReviews::route('/')];
    }
}
