<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\EventComments;

use App\Actions\Comments\ModerateComment;
use App\Enums\EventCommentStatus;
use App\Filament\Organizer\Resources\EventComments\Pages\ListEventComments;
use App\Models\EventComment;
use App\Models\Organizer;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * I commenti agli eventi che si organizzano.
 *
 * ## Perché la tenancy automatica di Filament qui non basta
 *
 * Filament filtra da sé quando il modello ha una relazione diretta col tenant.
 * Un commento però arriva al locale con **due salti** — commento → evento →
 * organizzatore — e quella relazione non esiste. Senza il filtro esplicito qui sotto
 * un organizzatore vedrebbe i commenti di tutti.
 *
 * È lo stesso ragionamento già scritto in `Venue/Resources/Events/EventResource`,
 * dove l'autorizzazione viene riscritta a mano invece di fidarsi del default.
 *
 * ## Qui non si cancella
 *
 * Un organizzatore ha un interesse diretto nei commenti sui propri eventi: può
 * **nascondere** — gesto reversibile e tracciato, con chi e quando — ma non
 * può far sparire la riga. Se potesse, una critica scomoda e uno spam
 * sarebbero indistinguibili a posteriori. Vedi `EventCommentPolicy`.
 */
class EventCommentResource extends Resource
{
    protected static ?string $model = EventComment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getModelLabel(): string
    {
        return __('comments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('comments.plural');
    }

    /** @return Builder<EventComment> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organizer, 403);

        return EventComment::query()->whereHas(
            'event',
            fn (Builder $query) => $query->where('organizer_id', $tenant->id)
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.title')->label(__('comments.singular'))->searchable()->wrap()->limit(60),
                TextColumn::make('user.name')->label(__('reviews.user'))->searchable(),
                TextColumn::make('body')->label(__('comments.body'))->wrap()->limit(140)->searchable(),
                TextColumn::make('reactions_count')->label(__('comments.reactions.like'))->sortable(),
                TextColumn::make('status')->label(__('reviews.status'))->badge()
                    ->formatStateUsing(fn (EventCommentStatus $state): string => $state->label())
                    ->color(fn (EventCommentStatus $state): string => $state === EventCommentStatus::Hidden ? 'danger' : 'success'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('moderator.name')->label(__('admin.fields.reviewed_by')),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('reviews.status'))->options(
                    collect(EventCommentStatus::cases())->mapWithKeys(fn (EventCommentStatus $s): array => [$s->value => $s->label()])->all()
                ),
            ])
            ->recordActions([
                Action::make('nascondi')->label(__('comments.moderation.hide'))->color('danger')->authorize('hide')
                    ->visible(fn (EventComment $record): bool => $record->status === EventCommentStatus::Published)
                    ->schema([
                        Textarea::make('testo')->label(__('comments.body'))->default(fn (EventComment $r): string => $r->body)->disabled()->dehydrated(false)->rows(6),
                        Hidden::make('revision')->default(fn (EventComment $r): int => $r->revision)->required(),
                        Textarea::make('note')->label(__('comments.moderation.note'))->maxLength(1000),
                    ])
                    ->action(function (EventComment $record, array $data): void {
                        $utente = auth()->user();
                        abort_unless($utente instanceof User, 403);
                        app(ModerateComment::class)->nascondi($record, $utente, (int) $data['revision'], $data['note'] ?? null);
                    }),
                Action::make('ripristina')->label(__('comments.moderation.restore'))->color('success')->authorize('restore')
                    ->visible(fn (EventComment $record): bool => $record->status === EventCommentStatus::Hidden)
                    ->schema([Hidden::make('revision')->default(fn (EventComment $r): int => $r->revision)->required()])
                    ->action(function (EventComment $record, array $data): void {
                        $utente = auth()->user();
                        abort_unless($utente instanceof User, 403);
                        app(ModerateComment::class)->ripristina($record, $utente, (int) $data['revision']);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEventComments::route('/')];
    }
}
