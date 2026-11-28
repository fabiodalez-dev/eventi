<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\UserRole;
use App\Models\NotificationText;
use App\Models\User;
use App\Support\NotificationTextCatalog;
use App\Support\SecurityLog;

/**
 * Le regole della pagina «Testi delle email», per il pannello e per l'app.
 *
 * Stavano dentro `App\Filament\Admin\Pages\NotificationTexts`; le ho
 * spostate qui così che pannello e app della redazione salvino, ripristinino
 * e controllino i segnaposti nello stesso modo. Le regole sono le stesse di
 * prima:
 *
 * - **si salvano solo le differenze**: un testo uguale all'originale, o
 *   svuotato, cancella la riga e torna a seguire il file di lingua;
 * - un testo non può usare segnaposti che l'originale non ha;
 * - le chiavi della newsletter le scrive solo il super admin (dalla pagina
 *   Newsletter): agli altri non compaiono, e nessun salvataggio o
 *   ripristino le tocca.
 */
final class NotificationTextEditor
{
    /**
     * Che pezzo dell'email è un testo, dall'ultima parte della chiave.
     *
     * Non l'ultima parte della chiave così com'è: «Subject», «When hours»,
     * «Line» sono nomi da programmatore in una pagina che chiede di scrivere
     * in italiano. Ciò che non è in elenco mantiene la chiave, perché un nome
     * sbagliato inganna più di uno tecnico.
     *
     * @var array<string, string>
     */
    private const PEZZI = [
        'subject' => 'Oggetto',
        'heading' => 'Titolo dentro il messaggio',
        'line' => 'Testo',
        'lines' => 'Testo',
        'why' => 'Perché ricevi questa email',
        'note' => 'Nota',
        'intro' => 'Apertura',
        'outro' => 'Chiusura',
        'empty' => 'Quando non c\'è niente da segnalare',
        'footer' => 'Piede',
        'greeting' => 'Saluto',
        'body' => 'Corpo',
        'cta' => 'Pulsante',
        'title' => 'Titolo',

        // etichette dei pulsanti
        'open_event' => 'Pulsante: apri l\'evento',
        'open_feed' => 'Pulsante: apri il feed',
        'open_tonight' => 'Pulsante: cosa c\'è stasera',
        'open_weekend' => 'Pulsante: il fine settimana',
        'open_panel' => 'Pulsante: apri il pannello',
        'open_venue' => 'Pulsante: apri il locale',

        // frammenti
        'at_venue' => 'Dove si svolge',
        'when_tomorrow' => 'Quando: domani',
        'when_hours' => 'Quando: fra N ore',
        'previous' => 'Com\'era prima',
        'reason' => 'Motivo',
        'mandatory' => 'Avviso: email di servizio',
        'link_fallback' => 'Se il pulsante non funziona',
        'unsubscribe' => 'Disiscrizione',
        'preferences' => 'Preferenze',
    ];

    /**
     * Questi testi partono dal nostro indirizzo verso tutti gli iscritti,
     * piede comune compreso: li riscrive l'amministrazione, non il
     * moderatore, che cura i contenuti degli altri.
     */
    public static function canEdit(?User $user): bool
    {
        return $user instanceof User && $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    /**
     * Le chiavi che questa persona non vede: quelle della newsletter, tranne
     * per il super admin.
     *
     * @return list<string>
     */
    public static function reserved(?User $user): array
    {
        return $user?->hasRole(UserRole::SuperAdmin->value) === true ? [] : array_values(NewsletterConfiguration::TEXTS);
    }

    /**
     * Il catalogo per gruppi, senza le chiavi riservate.
     *
     * @return array<string, array{etichetta: string, testi: array<string, array{predefinito: string, attuale: string, modificato: bool}>}>
     */
    public static function groups(?User $user): array
    {
        $reserved = array_flip(self::reserved($user));
        $groups = [];
        foreach (NotificationTextCatalog::perGruppo() as $name => $group) {
            $group['testi'] = array_diff_key($group['testi'], $reserved);
            if ($group['testi'] !== []) {
                $groups[$name] = $group;
            }
        }

        return $groups;
    }

    /** Se la persona vede e può scrivere questa chiave. */
    public static function editable(?User $user, string $key): bool
    {
        return array_key_exists($key, NotificationTextCatalog::predefiniti()) && ! in_array($key, self::reserved($user), true);
    }

    /**
     * I segnaposti usati in `$values` che l'originale della stessa chiave non
     * ha, chiave per chiave. Le chiavi riservate e quelle che non esistono
     * si ignorano, come nel pannello.
     *
     * @param  array<string, string>  $values  chiave completa => testo
     * @return array<string, list<string>>
     */
    public static function unknownVariables(?User $user, array $values): array
    {
        $defaults = array_diff_key(NotificationTextCatalog::predefiniti(), array_flip(self::reserved($user)));
        $unknown = [];
        foreach ($values as $key => $value) {
            if (! isset($defaults[$key])) {
                continue;
            }
            $extra = array_values(array_diff(NotificationTextCatalog::variabili($value), NotificationTextCatalog::variabili($defaults[$key])));
            if ($extra !== []) {
                $unknown[$key] = $extra;
            }
        }

        return $unknown;
    }

    /**
     * Il messaggio d'errore del pannello per i segnaposti sconosciuti.
     *
     * @param  list<string>  $tokens
     */
    public static function invalidMessage(array $tokens): string
    {
        return (string) __('notification_texts.invalid', ['tokens' => implode(', ', $tokens)]);
    }

    /**
     * Salva i testi di `$values` (le chiavi visibili alla persona, le altre si
     * ignorano). Va chiamato dopo il controllo dei segnaposti.
     *
     * Un campo svuotato non salva la stringa vuota: torna all'originale.
     * Un'email senza oggetto non è una scelta che qualcuno faccia apposta, ed
     * è un guasto che si scopre solo quando è partita.
     *
     * @param  array<string, string>  $values  chiave completa => testo
     * @return array{scritti: int, ripristinati: int}
     */
    public static function save(User $user, array $values): array
    {
        $written = 0;
        $reset = 0;
        $current = NotificationText::tutte();
        $before = [];
        $after = [];

        foreach (self::groups($user) as $group) {
            foreach ($group['testi'] as $key => $text) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }
                $new = trim($values[$key]);
                $old = $current[$key] ?? $text['predefinito'];
                $effective = $new === '' ? $text['predefinito'] : $new;
                if ($effective !== $old) {
                    $before[$key] = $old;
                    $after[$key] = $effective;
                }

                if ($new === '' || $new === $text['predefinito']) {
                    $reset += NotificationText::query()->where('key', $key)->delete();

                    continue;
                }

                NotificationText::updateOrCreate(['key' => $key], ['value' => $new, 'updated_by' => $user->getKey()]);
                $written++;
            }
        }

        NotificationText::dimenticaLaCache();

        if ($after !== []) {
            // Sono i testi che partono dall'indirizzo del sito verso tutti: chi li cambia, e da che cosa a che cosa.
            SecurityLog::configurazione('testi_email_cambiati', ['oggetto' => 'notification_texts', 'prima' => $before, 'dopo' => $after]);
        }

        return ['scritti' => $written, 'ripristinati' => $reset];
    }

    /** «Riporta tutto all'originale»: cancella le riscritture che la persona vede. */
    public static function resetAll(?User $user): void
    {
        $query = NotificationText::query()->whereNotIn('key', self::reserved($user));
        /** @var array<string, string> $before */
        $before = (clone $query)->orderBy('key')->pluck('value', 'key')->all();
        $query->delete();
        NotificationText::dimenticaLaCache();

        SecurityLog::configurazione('testi_email_ripristinati', ['oggetto' => 'notification_texts', 'prima' => $before]);
    }

    /**
     * I valori dimostrativi dei segnaposti, per l'esempio sotto ogni campo.
     *
     * @return array<string, string>
     */
    public static function samples(string $key): array
    {
        $samples = [];
        foreach (NotificationTextCatalog::predefiniti() as $text) {
            foreach (NotificationTextCatalog::variabili($text) as $variable) {
                $samples[':'.$variable] = __('notification_texts.examples.'.$variable) === 'notification_texts.examples.'.$variable
                    ? '['.$variable.']' : (string) __('notification_texts.examples.'.$variable);
            }
        }

        if ($key === 'notifications.moved.previous') {
            $samples[':when'] = (string) __('notification_texts.previous_example');
        }

        return $samples;
    }

    /** L'esempio con i dati dimostrativi: non si spedisce niente. */
    public static function preview(string $key, string $text): string
    {
        return strtr($text, self::samples($key));
    }

    public static function label(string $key): string
    {
        $last = (string) last(explode('.', $key));

        return self::PEZZI[$last] ?? ucfirst(str_replace('_', ' ', $last));
    }

    /** Righe proporzionate al testo: un oggetto non ha bisogno di sei righe. */
    public static function rows(string $default): int
    {
        return max(2, min(6, (int) ceil(mb_strlen($default) / 70)));
    }

    /**
     * La revisione di un testo, per il controllo dell'app: cambia quando
     * qualcuno lo riscrive o lo riporta all'originale.
     */
    public static function revision(string $key): string
    {
        $override = NotificationText::tutte()[$key] ?? null;

        return substr(hash('sha256', $key.'|'.($override === null ? '0' : '1:'.$override)), 0, 16);
    }
}
