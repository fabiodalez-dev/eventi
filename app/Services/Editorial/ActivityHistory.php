<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\Event;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * La cronologia delle modifiche della redazione (R-EV-25), letta da
 * `activity_log`: come si racconta una riga e che cosa rimette «Ripristina».
 *
 * Stava dentro `ActivityRelationManager` del pannello; qui la chiamano sia la
 * sezione «Modifiche» degli eventi, dei locali e delle abilitazioni sia
 * l'API dell'app, così la regola dei campi ripristinabili è una sola.
 *
 * Il registro resta in sola lettura: il ripristino è una modifica come le
 * altre e lascia la sua riga.
 */
final class ActivityHistory
{
    /**
     * I soli campi che «Ripristina» può rimettere: contenuti. Stato,
     * pubblicazione, verifica, piano, vetrina e ogni altra decisione della
     * redazione passano dalla moderazione, con le sue notifiche e i suoi
     * controlli, e non si riavvolgono da una riga di cronologia.
     */
    public const RESTORABLE = [
        Event::class => ['title', 'subtitle', 'description', 'short_description', 'price_type', 'price_min', 'price_max', 'price_notes',
            'ticket_url', 'booking_url', 'booking_phone', 'age_restriction', 'venue_id', 'organizer_id', 'organizer_name', 'organizer_url',
            'category_id', 'external_links', 'facts', 'content_details', 'video_url'],
        Venue::class => ['name', 'description', 'short_description', 'address', 'address_extra', 'postal_code', 'municipality', 'zone',
            'phone', 'email', 'website', 'socials', 'opening_hours', 'transit', 'capacity', 'accessibility', 'info', 'requires_membership',
            'membership_notes', 'type'],
    ];

    /** L'etichetta del tipo di riga (creato, modificato, eliminato, unito…). */
    public static function eventLabel(?string $event): string
    {
        return match ($event) {
            'created' => __('admin.activity.created'),
            'updated' => __('admin.activity.updated'),
            'deleted' => __('admin.activity.deleted'),
            'merged' => __('admin.activity.merged'),
            'merged_into' => __('admin.activity.merged_into'),
            default => (string) $event,
        };
    }

    /** Chi ha fatto la modifica: il nome, «Sistema» senza autore, o un account che non c'è più. */
    public static function causerLabel(Activity $activity): string
    {
        $name = $activity->causer?->getAttribute('name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $activity->causer_id === null ? __('admin.placeholders.system') : __('admin.activity.unavailable_causer');
    }

    /**
     * I valori «prima» di una riga, limitati ai campi ripristinabili del
     * modello a cui appartiene.
     *
     * @return array<string, mixed>
     */
    public static function previous(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?: ($activity->properties?->toArray() ?? []);
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $allowed = match ($activity->subject_type) {
            (new Event)->getMorphClass() => self::RESTORABLE[Event::class],
            (new Venue)->getMorphClass() => self::RESTORABLE[Venue::class],
            default => [],
        };

        return array_intersect_key($old, array_flip($allowed));
    }

    /** Vero se «Ripristina» si mostra su questa riga di questo record (oltre al permesso `update`). */
    public static function restorable(Activity $activity, Model $owner): bool
    {
        return $activity->event === 'updated' && self::previous($activity) !== []
            && ($owner instanceof Event || $owner instanceof Venue);
    }

    /**
     * Rimette i valori di prima dei campi toccati dalla riga. Falso se non
     * c'è niente da rimettere (il pannello lo dice con un avviso).
     */
    public static function restore(Model $owner, Activity $activity): bool
    {
        $previous = self::previous($activity);
        if ($previous === []) {
            return false;
        }
        foreach ($previous as $key => $value) {
            $owner->setAttribute($key, $value);
        }
        $owner->save();

        return true;
    }

    /** «Campo: prima → dopo · Campo: …», come la colonna «Modifiche» del pannello. */
    public static function describe(Activity $activity): string
    {
        // Current activitylog stores changes separately; keep older records readable.
        $changes = $activity->attribute_changes?->toArray() ?: ($activity->properties?->toArray() ?? []);
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
        $parts = [];
        $isGrant = $activity->subject_type === (new SponsorshipGrant)->getMorphClass();
        $paymentLabels = ['amount_cents' => 'amount', 'paid_at' => 'paid',
            'payment_method' => 'method', 'payment_reference' => 'reference', 'complimentary' => 'complimentary'];

        foreach (array_unique([...array_keys($old), ...array_keys($attributes)]) as $key) {
            $label = $isGrant && isset($paymentLabels[$key]) ? __('promotions.'.$paymentLabels[$key]) : __('admin.fields.'.$key);
            $label = $label === 'admin.fields.'.$key ? $key : $label;
            $before = array_key_exists($key, $old) ? self::stringifyAttribute($key, $old[$key], $isGrant).' → ' : '';
            $parts[] = $label.': '.$before.self::stringifyAttribute($key, $attributes[$key] ?? null, $isGrant);
        }

        return implode(' · ', $parts);
    }

    private static function stringifyAttribute(string $key, mixed $value, bool $isGrant): string
    {
        return $isGrant && $key === 'amount_cents' && is_numeric($value)
            ? number_format(((float) $value) / 100, 2, ',', '.').' €'
            : self::stringify($value);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => __('admin.placeholders.none'),
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
        };
    }
}
