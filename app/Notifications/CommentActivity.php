<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EventCommentReactionType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\EventComment;
use App\Services\Notifications\ChannelSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * «Qualcuno ha risposto al tuo commento» e «a qualcuno piace il tuo commento».
 *
 * ## Perché una classe sola per due casi
 *
 * Le due notifiche hanno la stessa forma — chi, su quale evento, cosa ha
 * fatto, dove andare a vedere — e tenerle insieme impedisce che fra sei mesi
 * una punti a una pagina che l'altra non conosce più. È lo stesso ragionamento
 * scritto in testa a `VenueModerated`.
 *
 * ## Il canale non si decide qui
 *
 * Lo sceglie `ChannelSelector`, come per tutto il resto del progetto:
 * preferenza esplicita dell'utente, altrimenti push se ha un dispositivo
 * attivo, altrimenti email. Il canale `database` si aggiunge **sempre**, così
 * la notifica resta nell'archivio in-app anche quando l'email non parte.
 */
class CommentActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationType $tipo,
        private readonly EventComment $commento,
        private readonly string $attore,
        private readonly ?EventCommentReactionType $reazione = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $canale = app(ChannelSelector::class)->for($notifiable);

        $canali = match ($canale) {
            NotificationChannel::Push => [WebPushChannel::class, FcmChannel::class],
            NotificationChannel::Both => ['mail', WebPushChannel::class, FcmChannel::class],
            NotificationChannel::Database => [],
            default => ['mail'],
        };

        /* L'archivio in-app riceve sempre, qualunque sia la scelta. */
        $canali[] = 'database';

        return $canali;
    }

    /**
     * Ultima verifica prima dell'invio: fra la messa in coda e il momento in
     * cui il lavoro gira, la persona può aver spento questa tipologia.
     */
    public function shouldSend(object $notifiable): bool
    {
        return $this->tipo->isEnabledFor($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titolo())
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line($this->titolo())
            ->line('« '.Str::limit($this->commento->body, 140).' »')
            ->action(__('comments.title'), $this->indirizzo());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->tipo->value,
            'comment_id' => $this->commento->id,
            'event_id' => $this->commento->event_id,
            'actor' => $this->attore,
            'reaction' => $this->reazione?->value,
            'title' => $this->titolo(),
            'url' => $this->indirizzo(),
        ];
    }

    private function titolo(): string
    {
        return match ($this->tipo) {
            NotificationType::CommentReply => __('comments.notifications.reply', ['name' => $this->attore]),
            NotificationType::CommentReaction => __('comments.notifications.reaction', ['name' => $this->attore, 'reaction' => $this->reazione?->label() ?? '']),
            NotificationType::CommentModerated => __('comments.notifications.moderated'),
            default => __('comments.notifications.new', ['name' => $this->attore]),
        };
    }

    private function indirizzo(): string
    {
        $slug = $this->commento->event?->slug;

        return $slug === null
            ? url('/')
            : route('events.show', ['slug' => $slug]).'#commento-'.$this->commento->id;
    }
}
