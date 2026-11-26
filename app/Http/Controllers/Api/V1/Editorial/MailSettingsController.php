<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Editorial\EditorialSettings;
use App\Services\Settings\MailConfigurationTester;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La configurazione della posta (R-SY-09), come la pagina del pannello:
 * solo il super admin (admin e moderatore ricevono 403).
 *
 * Nell'app (decisione 5) si legge e si manda il messaggio di prova; le
 * credenziali SMTP, il mittente e l'interruttore «usa questa configurazione»
 * si scrivono solo dal sito. La password non esce mai: `has_password`.
 */
final class MailSettingsController extends Controller
{
    public function show(EditorialSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->mail($this->superAdmin())]);
    }

    /**
     * Il messaggio di prova con la configurazione salvata, all'indirizzo di
     * chi lo chiede. Se parte, la configurazione risulta provata (il sito
     * permette di accenderla); se il server di posta rifiuta, la risposta
     * porta il suo messaggio per esteso, come la notifica del pannello; un
     * guasto che non è del trasporto va nel registro degli errori e qui
     * arriva una frase generica (`MailConfigurationTester::failureDetail`). In
     * tutti e due i casi è un 200: la prova è andata, con un esito.
     */
    public function test(EditorialSettings $settings): JsonResponse
    {
        $user = $this->superAdmin();
        $recipient = $user->email;
        if ($recipient === '') {
            throw ValidationException::withMessages(['recipient' => __('mail_settings.test.no_recipient')]);
        }

        try {
            MailConfigurationTester::send(MailConfigurationTester::savedValues(), $recipient);
            $result = [
                'sent' => true,
                'title' => (string) __('mail_settings.test.sent', ['email' => $recipient]),
                'detail' => (string) __('mail_settings.test.sent_body'),
            ];
        } catch (Throwable $e) {
            $result = [
                'sent' => false,
                'title' => (string) __('mail_settings.test.failed'),
                'detail' => MailConfigurationTester::failureDetail($e),
            ];
        }

        return response()->json([
            'data' => ['result' => $result, 'mail' => $settings->mail($user)],
            'meta' => ['message' => $result['title']],
        ]);
    }

    private function superAdmin(): User
    {
        $user = EditorialDesk::require()->user;
        abort_unless(MailConfigurationTester::canManage($user), 403);

        return $user;
    }
}
