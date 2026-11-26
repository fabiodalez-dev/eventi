<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Troppe password sbagliate allo sblocco della redazione nell'app: il token
 * di quel telefono è stato revocato (`EditorialUnlocks::LOCKOUT_FAILURES`
 * errori in `LOCKOUT_HOURS` ore).
 *
 * Due destinatari, due testi: la persona dell'account («se non eri tu, cambia
 * la password») e i super admin («l'account X è stato fermato»), che sono
 * quelli che possono guardare il registro di sicurezza e decidere il resto.
 */
class EditorialUnlockLockout extends Notification
{
    public function __construct(
        private readonly User $account,
        private readonly int $failures,
        private readonly ?string $ip,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = config()->string('app.name');
        $vars = ['product' => $product, 'count' => $this->failures, 'account' => (string) $this->account->email, 'ip' => $this->ip ?? '—'];

        if ($notifiable instanceof User && $notifiable->is($this->account)) {
            return (new MailMessage)
                ->subject(__('pannelli-redazione.sblocco_fermato.subject_owner', $vars))
                ->line(__('pannelli-redazione.sblocco_fermato.owner_intro', $vars))
                ->line(__('pannelli-redazione.sblocco_fermato.owner_action', $vars));
        }

        return (new MailMessage)
            ->subject(__('pannelli-redazione.sblocco_fermato.subject_admin', $vars))
            ->line(__('pannelli-redazione.sblocco_fermato.admin_intro', $vars))
            ->line(__('pannelli-redazione.sblocco_fermato.admin_action', $vars));
    }
}
