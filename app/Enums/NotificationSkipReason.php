<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Perché un invio previsto non è partito (§15.5: «skipped **con motivo**»).
 *
 * Il motivo si scrive in `scheduled_notifications.last_error`, che è l'unica
 * colonna di testo libero della tabella. Un invio saltato senza motivo
 * scritto da qualche parte è indistinguibile da un guasto silenzioso, ed è
 * esattamente ciò che §15.5 chiede di evitare quando pretende che ogni invio
 * previsto sia ispezionabile.
 */
enum NotificationSkipReason: string
{
    case AccountDeleted = 'account_deleted';
    case Unverified = 'unverified';
    case PreferenceOff = 'preference_off';
    case FrequencyCap = 'frequency_cap';
    case OccurrencePast = 'occurrence_past';
    case OccurrenceCancelled = 'occurrence_cancelled';
    case NotSaved = 'not_saved';
    case NothingToSend = 'nothing_to_send';
    case MissingSubject = 'missing_subject';

    public function label(): string
    {
        return __('enums.notification_skip_reason.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
