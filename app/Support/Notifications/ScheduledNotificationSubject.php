<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\EventOccurrence;
use App\Models\ScheduledNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Il soggetto di un invio previsto detto a parole: «Data · Concerto
 * all'alba». Lo usano la colonna «Soggetto» della risorsa
 * `ScheduledNotificationResource` del pannello e l'elenco
 * `GET /api/v1/editorial/scheduled-notifications` dell'app (R-SY-05), così la
 * stessa riga si legge allo stesso modo nei due posti.
 *
 * La colonna morph porta un alias breve (`event_occurrence`), non un nome
 * di classe: si traduce, invece di mostrare a chi legge una stringa da
 * programmatori. Chi chiama carica prima `notifiable` (e per una data il suo
 * `event`): qui non si fa nessuna query.
 */
final class ScheduledNotificationSubject
{
    /**
     * Le relazioni che una riga dell'elenco mostra: il destinatario e il
     * soggetto (la data con il suo evento). Senza, ogni riga faceva due query
     * per conto proprio.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'user',
            'notifiable' => static function (Relation $morph): void {
                if ($morph instanceof MorphTo) {
                    $morph->morphWith([EventOccurrence::class => ['event']]);
                }
            },
        ];
    }

    public static function describe(ScheduledNotification $notification): string
    {
        $type = $notification->notifiable_type;

        if (! is_string($type) || $type === '') {
            return '';
        }

        $key = 'admin.resources.'.$type.'.label';
        $label = __($key);
        $label = $label === $key ? $type : $label;

        $subject = $notification->notifiable;

        if (! $subject instanceof Model) {
            return $label;
        }

        $title = $subject instanceof EventOccurrence
            ? $subject->event->title
            : $subject->getAttribute('title') ?? $subject->getAttribute('name') ?? $subject->getKey();

        return $label.' '.__('common.separator').' '.(string) $title;
    }
}
