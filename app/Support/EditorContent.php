<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Tiptap\Editor;

final class EditorContent
{
    public const FIELDS = [
        'name', 'title', 'subtitle', 'description', 'short_description', 'organizer_name', 'url', 'note', 'highlight',
        'address', 'address_extra', 'municipality', 'zone', 'postal_code', 'phone', 'email',
        'website', 'organizer_url', 'ticket_url', 'booking_url', 'booking_phone', 'price_notes',
        'age_restriction', 'membership_notes', 'status_note', 'booking_instructions',
        'content_details', 'seo', 'external_links', 'facts', 'custom_location', 'socials',
        'opening_hours', 'transit', 'accessibility', 'info', 'default_event_settings',
        'raw_text', 'message', 'venue_name', 'venue_hint', 'contact_name', 'contact_role', 'contact_phone', 'contact_email',
    ];

    private const RICH = ['description', 'introduction', 'text', 'answer', 'membership_notes',
        'accessibility_notes', 'parking_notes', 'transit_notes', 'entrance_notes', 'mandatory_costs',
        'weather_policy', 'minors_policy', 'cancellation_policy', 'refund_policy', 'public_contact',
        'poster_alt', 'poster_caption', 'poster_credit', 'status_note', 'booking_instructions', 'raw_text', 'message'];

    public static function clean(string $field, mixed $value, string $root = ''): mixed
    {
        $root = $root === '' ? $field : $root;
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }
        if (is_array($value) && ($value['type'] ?? null) === 'doc' && in_array($field, self::RICH, true)) {
            return Description::sanitize((new Editor)->setContent($value)->getHTML());
        }
        if (is_array($value)) {
            if ($field === 'content_details') {
                $value = array_intersect_key($value, array_flip([
                    'introduction', 'city_introductions', 'parking_type', 'parking_notes', 'transit_notes',
                    'entrance_notes', 'accessibility', 'accessibility_notes', 'membership', 'membership_notes',
                    'feature_ids', 'practical_custom', 'organizer_venue_id', 'minimum_age', 'attendance_mode',
                    'online_url', 'organizer_type', 'mandatory_costs', 'weather_policy', 'minors_policy',
                    'cancellation_policy', 'refund_policy', 'public_contact', 'poster_alt', 'poster_caption',
                    'poster_credit', 'agenda', 'faqs',
                ]));
            }
            if ($field === 'seo') {
                $value = array_intersect_key($value, array_flip(['title', 'description', 'image', 'indexing']));
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::clean((string) $key, $item, $root);
            }

            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (($root === 'socials' || ($root === 'seo' && $field === 'image') || in_array($field, ['website', 'organizer_url', 'ticket_url', 'booking_url', 'online_url', 'url', 'href'], true)) && $value !== '') {
            if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw ValidationException::withMessages([$root => 'Inserisci un indirizzo web valido che inizi con https:// o http://.']);
            }

            return $value;
        }
        $rich = in_array($root, ['description', 'membership_notes', 'status_note', 'booking_instructions', 'content_details', 'raw_text', 'message'], true)
            && $field !== 'poster_alt' && in_array($field, self::RICH, true);
        if ($value === strip_tags($value)) {
            return $value;
        }

        return $rich ? Description::sanitize($value) : strip_tags(Description::sanitize($value));
    }
}
