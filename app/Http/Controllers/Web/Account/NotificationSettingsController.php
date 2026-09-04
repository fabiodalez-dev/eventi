<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\UpdateNotificationSettingsRequest;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notifications\ChannelSelector;
use App\Support\Features;
use App\Support\Notifications\PreferenceLinks;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La pagina delle preferenze **raggiungibile senza accesso** e la disiscrizione
 * a un click (§15.9).
 *
 * Perché senza accesso: chi riceve un'email indesiderata alle sette del
 * mattino non deve ricordarsi una password per farla smettere. L'indirizzo è
 * firmato e a scadenza, quindi vale come prova che quell'indirizzo è suo —
 * esattamente la stessa prova che serve, né una di più.
 *
 * Cosa **non** si può fare da qui: cambiare nome, lingua, fuso o email. Quelle
 * sono modifiche di identità e vogliono una sessione. Qui si può solo smettere
 * di ricevere, che è il diritto che la pagina esiste per esercitare.
 */
final class NotificationSettingsController extends Controller
{
    public function edit(Request $request, User $user, ChannelSelector $channels): View
    {
        /*
         * L'interruttore delle push compare solo a chi ha una sessione aperta
         * su questo stesso account (§15.6, D54). Il collegamento firmato basta
         * a spegnere le notifiche, non ad accendere un canale che le dirotta
         * su uno schermo: vedi `PushSubscriptionController`.
         *
         * E compare solo se le chiavi VAPID ci sono davvero: senza, il canale
         * non esiste e l'interruttore mentirebbe.
         */
        $own = Auth::id() === $user->getKey();

        return view('account.notifications.preferences', [
            'user' => $user,
            'action' => PreferenceLinks::preferences($user),
            'pushAvailable' => $own && $channels->configured(),
            'pushKey' => (string) config('webpush.vapid.public_key'),
            'pushActive' => $own && WebPushSubscription::query()
                ->where('user_id', $user->getKey())
                ->usable()
                ->exists(),
            'meta' => $this->meta(__('notifications.preferences.title'), __('notifications.preferences.lead')),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request, User $user): RedirectResponse
    {
        $user->notification_preferences = $request->preferences()->toArray();
        $user->daily_digest_time = $request->string('daily_digest_time')->value() === ''
            ? null
            : $request->string('daily_digest_time')->value();
        $user->quiet_hours = $request->quietHours();

        /*
         * Il consenso al marketing conserva la propria data (§15.9):
         * riconfermarlo non la riscrive, toglierlo la cancella. È la data la
         * prova, non il booleano.
         *
         * Con la newsletter spenta la casella non è nel modulo, e la sua
         * assenza non è una revoca: il consenso già dato resta com'è.
         */
        if (Features::newsletterActive()) {
            $user->marketing_opt_in_at = $request->boolean('marketing_opt_in')
                ? ($user->marketing_opt_in_at ?? Carbon::now())
                : null;
        }

        $user->save();

        return redirect()
            ->to(PreferenceLinks::preferences($user))
            ->with('status', __('notifications.preferences.saved'));
    }

    /**
     * La disiscrizione a un click. Il collegamento nell'email è un `GET` e
     * spegne davvero: chiedere una conferma dopo aver promesso «un click»
     * sarebbe due click, ed è il punto in cui la gente segna il messaggio come
     * spam invece di insistere.
     *
     * Lo stesso indirizzo esiste in `POST` per il pulsante nativo dei client
     * di posta (RFC 8058), che lo chiama da solo.
     */
    public function unsubscribe(Request $request, User $user, string $type): View
    {
        $notificationType = NotificationType::tryFrom($type);

        $stopped = $notificationType instanceof NotificationType && $notificationType->disableFor($user);

        return view('account.notifications.unsubscribed', [
            'user' => $user,
            'type' => $notificationType,
            'stopped' => $stopped,
            'preferencesUrl' => PreferenceLinks::preferences($user),
            'meta' => $this->meta(__('notifications.unsubscribed.title'), __('notifications.preferences.lead')),
        ]);
    }

    private function meta(string $title, string $description): PageMeta
    {
        return new PageMeta(
            title: $title,
            heading: $title,
            description: $description,
            indexable: false,
        );
    }
}
