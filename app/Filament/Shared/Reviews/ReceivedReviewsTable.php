<?php

declare(strict_types=1);

namespace App\Filament\Shared\Reviews;

use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le recensioni ricevute, uguali nei pannelli del locale e dell'organizzatore.
 *
 * Si vedono solo quelle pubblicate — le stesse della scheda pubblica — e qui
 * non si modera: approvare e rifiutare restano della redazione, che non ha un
 * interesse diretto nel giudizio. Il gestore può soltanto rispondere, in
 * pubblico e con il nome della scheda.
 */
final class ReceivedReviewsTable
{
    /**
     * @param  Builder<CatalogReview>  $query
     * @return Builder<CatalogReview>
     */
    public static function scope(Builder $query, Venue|Organizer $subject): Builder
    {
        return app(CatalogReviews::class)->onlyPublished($query, $subject);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ratings', 'user.communityProfile', 'reviewable']))
            ->emptyStateHeading(__('reviews.reply.empty'))
            ->columns([
                TextColumn::make('author')->label(__('reviews.user'))
                    ->state(fn (CatalogReview $record): string => (string) app(CatalogReviews::class)->resource($record)['author']),
                TextColumn::make('rating')->label(__('reviews.rating'))->color('warning')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : str_repeat('★', $state).str_repeat('☆', 5 - $state)),
                TextColumn::make('body')->label(__('reviews.singular'))->wrap()->limit(200),
                TextColumn::make('moderated_at')->label(__('reviews.reply.published_at'))->date('d/m/Y')->sortable(),
                TextColumn::make('owner_reply')->label(__('reviews.reply.column'))->wrap()->limit(160)->placeholder('—'),
            ])
            ->defaultSort('moderated_at', 'desc')
            ->recordActions([
                Action::make('reply')
                    ->label(fn (CatalogReview $record): string => blank($record->owner_reply) ? __('reviews.reply.write') : __('reviews.reply.edit'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->authorize('reply')
                    ->schema([
                        Textarea::make('review_body')->label(__('reviews.singular'))->default(fn (CatalogReview $record): string => $record->body ?? '')->disabled()->dehydrated(false)->rows(5),
                        Textarea::make('reply')->label(__('reviews.reply.field'))->helperText(__('reviews.reply.hint'))
                            ->default(fn (CatalogReview $record): ?string => $record->owner_reply)->required()->minLength(2)->maxLength(2000)->rows(5),
                    ])
                    ->action(function (CatalogReview $record, array $data): void {
                        app(CatalogReviews::class)->reply($record, self::user(), (string) $data['reply']);
                        Notification::make()->success()->title(__('reviews.reply.saved'))->send();
                    }),
                Action::make('removeReply')
                    ->label(__('reviews.reply.remove'))->color('danger')->requiresConfirmation()
                    ->modalDescription(__('reviews.reply.remove_confirm'))
                    ->visible(fn (CatalogReview $record): bool => filled($record->owner_reply))
                    ->authorize('removeReply')
                    ->action(function (CatalogReview $record): void {
                        app(CatalogReviews::class)->reply($record, self::user(), null);
                        Notification::make()->success()->title(__('reviews.reply.removed'))->send();
                    }),
            ]);
    }

    private static function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
