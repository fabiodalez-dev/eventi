<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * «Ci sono sponsorizzazioni in ritardo o a rischio», alla redazione.
 *
 * Lo manda `sponsorships:pacing-check` una volta al giorno, solo quando c'è
 * qualcosa da guardare. Va nel canale `database` con la forma delle notifiche
 * di Filament (`format = filament`), così compare nella campanella del
 * pannello quando le notifiche del pannello sono accese, e porta anche
 * `title`, `body` e `url`, che sono i campi che legge la casella degli avvisi
 * del sito (`UnifiedNotifications`).
 *
 * Niente email: un avviso di ogni mattina per posta diventa rumore in una
 * settimana, e la cosa da fare (guardare, alzare la priorità, sentire il
 * cliente) si fa dal pannello.
 */
class SponsorshipPacingAlert extends Notification
{
    /**
     * @param  list<array{name: string, state: string}>  $units  le unità da guardare, già con il nome leggibile
     */
    public function __construct(
        private readonly array $units,
        private readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $title = trans_choice('sponsorship-panel.pacing_check.title', count($this->units), ['count' => count($this->units)]);
        $body = $this->body();

        return [
            ...FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->warning()
                ->actions([Action::make('open')->label(__('sponsorship-panel.pacing_check.action'))->url($this->url)])
                ->getDatabaseMessage(),
            'category' => 'sponsorships',
            'url' => $this->url,
            'units' => $this->units,
        ];
    }

    /** I primi tre per nome, poi «e altre N». */
    private function body(): string
    {
        $shown = array_slice($this->units, 0, 3);
        $names = implode(', ', array_map(
            static fn (array $unit): string => __('sponsorship-panel.pacing_check.unit', $unit),
            $shown,
        ));
        $others = count($this->units) - count($shown);

        if ($others > 0) {
            $names .= ' '.trans_choice('sponsorship-panel.pacing_check.more', $others, ['count' => $others]);
        }

        return __('sponsorship-panel.pacing_check.body', ['units' => $names]);
    }
}
