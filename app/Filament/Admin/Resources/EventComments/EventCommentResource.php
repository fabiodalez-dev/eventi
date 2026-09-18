<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventComments;

use App\Actions\Comments\ModerateComment;
use App\Enums\EventCommentStatus;
use App\Filament\Admin\Resources\EventComments\Pages\ListEventComments;
use App\Models\EventComment;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * I commenti agli eventi, visti dall'amministrazione.
 *
 * ## Tre azioni, e una sola è distruttiva
 *
 * `nascondi` e `ripristina` cambiano lo stato e restano a registro: chi, quando,
 * con quale motivo. `elimina` fa sparire la riga, ed è l'unica cosa che qui
 * dentro non si può disfare — per questo è riservata all'amministratore, e non
 * compare nei pannelli di locali e organizzatori.
 *
 * ## Il badge conta i nascosti, non i nuovi
 *
 * Su `CatalogReviews` il badge conta le recensioni in attesa, perché lì c'è una
 * coda da smaltire. I commenti si pubblicano subito: una coda non esiste, e un
 * badge che contasse i commenti nuovi sarebbe un numero che cresce sempre e
 * che nessuno può azzerare. Contare i nascosti invece indica lavoro vero —
 * decisioni prese da un locale che forse vanno riviste.
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
        $count = EventComment::query()->where('status', EventCommentStatus::Hidden)->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.title')->label(__('comments.moderation.event'))->searchable()->wrap()->limit(60),
                TextColumn::make('user.name')->label(__('reviews.user'))->searchable(),
                TextColumn::make('body')->label(__('comments.moderation.body'))->wrap()->limit(140)->searchable(),
                TextColumn::make('parent_id')->label(__('comments.reply'))->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '↳'),
                TextColumn::make('reactions_count')->counts('reactions')->label(__('comments.reactions.like'))->sortable(),
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
                ViewAction::make('dettagli')
                    ->label(__('comments.moderation.details'))
                    ->authorize('hide')
                    ->modalSubmitAction(false)
                    ->schema([
                        TextEntry::make('event.title')->label(__('comments.moderation.event')),
                        TextEntry::make('user.name')->label(__('reviews.user')),
                        TextEntry::make('body')->label(__('comments.moderation.body')),
                        TextEntry::make('moderation_note')->label(__('comments.moderation.note'))->placeholder('—'),
                        TextEntry::make('moderator.name')->label(__('admin.fields.reviewed_by'))->placeholder('—'),
                        TextEntry::make('moderated_at')->label(__('comments.moderation.date'))->dateTime('d/m/Y H:i')->placeholder('—'),
                    ]),
                self::azioneNascondi(),
                self::azioneRipristina(),
                DeleteAction::make()->label(__('comments.delete'))->authorize('delete')
                    ->modalDescription(__('comments.delete_confirm')),
            ]);
    }

    private static function azioneNascondi(): Action
    {
        return Action::make('nascondi')
            ->label(__('comments.moderation.hide'))
            ->color('danger')
            ->authorize('hide')
            ->visible(fn (EventComment $record): bool => $record->status === EventCommentStatus::Published)
            ->schema([
                Textarea::make('testo')->label(__('comments.body'))
                    ->default(fn (EventComment $record): string => $record->body)
                    ->disabled()->dehydrated(false)->rows(6),
                /*
                 * La revisione letta all'apertura del riquadro. Se un altro
                 * moderatore agisce nel frattempo, l'azione si rifiuta invece
                 * di sovrascrivere la sua decisione in silenzio.
                 */
                Hidden::make('revision')->default(fn (EventComment $record): int => $record->revision)->required(),
                Textarea::make('note')->label(__('comments.moderation.note'))->maxLength(1000),
            ])
            ->action(function (EventComment $record, array $data): void {
                $moderatore = auth()->user();
                abort_unless($moderatore instanceof User, 403);
                app(ModerateComment::class)->nascondi($record, $moderatore, (int) $data['revision'], $data['note'] ?? null);
            });
    }

    private static function azioneRipristina(): Action
    {
        return Action::make('ripristina')
            ->label(__('comments.moderation.restore'))
            ->color('success')
            ->authorize('restore')
            ->visible(fn (EventComment $record): bool => $record->status === EventCommentStatus::Hidden)
            ->schema([
                Hidden::make('revision')->default(fn (EventComment $record): int => $record->revision)->required(),
            ])
            ->action(function (EventComment $record, array $data): void {
                $moderatore = auth()->user();
                abort_unless($moderatore instanceof User, 403);
                app(ModerateComment::class)->ripristina($record, $moderatore, (int) $data['revision']);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListEventComments::route('/')];
    }
}
