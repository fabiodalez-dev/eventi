<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventComments;

use App\Actions\Comments\ModerateComment;
use App\Enums\EventCommentStatus;
use App\Filament\Admin\Resources\EventComments\Pages\ListEventComments;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Filament\Support\PosterColumn;
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
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

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
    use HasSentenceCaseNavigationLabel;

    /** Titoli e briciole in italiano: «Commenti agli eventi», non «Commenti Agli Eventi». */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = EventComment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 3;

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
        $count = Cache::remember('nav-badge:event-comments-hidden', 60, fn (): int => EventComment::query()->where('status', EventCommentStatus::Hidden)->count());

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            /* Locandina, autore e moderatore nella stessa query: senza, ogni
               riga ne chiederebbe tre. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['event.media', 'user', 'moderator']))
            ->columns([
                /*
                 * La riga è il commento, riconoscibile dalla locandina
                 * dell'evento: sul telefono le sette colonne di prima si
                 * tagliavano, e il testo — la sola cosa da leggere per
                 * decidere — finiva fuori schermo.
                 */
                Split::make([
                    PosterColumn::make('event.poster_media'),
                    Stack::make([
                        TextColumn::make('event.title')->extraAttributes(['class' => 'py-0.5!'])->label(__('comments.moderation.event'))->searchable()->weight(FontWeight::SemiBold)->wrap()->limit(80),
                        TextColumn::make('user.name')->extraAttributes(['class' => 'py-0.5!'])->label(__('reviews.user'))->searchable()->color('gray')->size(TextSize::Small)
                            ->formatStateUsing(fn (string $state, EventComment $record): string => collect([
                                $state,
                                $record->parent_id === null ? null : __('admin.comment_list.reply'),
                                $record->created_at?->copy()->timezone(FilamentTimezone::get())->format('d/m/Y H:i'),
                            ])->filter()->join(' · ')),
                        TextColumn::make('body')->extraAttributes(['class' => 'py-0.5!'])->label(__('comments.moderation.body'))->wrap()->limit(220)->searchable(),
                        TextColumn::make('status')->extraAttributes(['class' => 'py-0.5!'])->label(__('reviews.status'))->badge()
                            ->formatStateUsing(fn (EventCommentStatus $state): string => $state->label())
                            ->color(fn (EventCommentStatus $state): string => $state === EventCommentStatus::Hidden ? 'danger' : 'success'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('reactions_count')->extraAttributes(['class' => 'py-0.5!'])->counts('reactions')->label(__('comments.reactions.like'))->sortable()->size(TextSize::Small)
                            ->formatStateUsing(fn (int $state): string => trans_choice('admin.comment_list.reactions', $state, ['count' => $state])),
                        TextColumn::make('moderator.name')->extraAttributes(['class' => 'py-0.5!'])->label(__('admin.fields.reviewed_by'))->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (string $state): string => __('admin.comment_list.moderated_by', ['name' => $state])),
                    ])->space(1)->alignment(Alignment::End)->grow(false)->visibleFrom('lg'),
                ]),
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
            ], position: RecordActionsPosition::AfterContent);
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
