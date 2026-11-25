package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.Poster
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull

/*
 * Gli eventi della gestione (P1): la card dell'elenco, la scheda con i campi
 * del modulo, le scelte del modulo, gli avvisi sulla data, l'import da
 * Facebook. Ogni campo ha un valore predefinito: un server più vecchio o più
 * nuovo non deve far fallire la lettura.
 */

/** Un valore con la sua etichetta in italiano, come lo manda il server (`status`, `price_type`). */
@Serializable
internal data class LabeledValue(val value: String = "", val label: String = "")

@Serializable
internal data class EventSourceInfo(val value: String = "", val label: String = "", val imported: Boolean = false)

@Serializable
internal data class NamedRef(val id: Long = 0, val name: String = "")

/** Una card dell'elenco eventi (`GET S/events`). */
@Serializable
internal data class ManagedEventCard(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val source: EventSourceInfo = EventSourceInfo(),
    val poster: Poster? = null,
    @SerialName("next_starts_at") val nextStartsAt: String? = null,
    @SerialName("last_starts_at") val lastStartsAt: String? = null,
    val past: Boolean = false,
    val venue: NamedRef? = null,
    /* La prossima data in breve (P2): «Tutto esaurito» dalla card. */
    @SerialName("next_date") val nextDate: ManagedNextDate? = null,
)

@Serializable
internal data class ManagedEventsPage(
    val data: List<ManagedEventCard> = emptyList(),
    val meta: ManagedPageMeta = ManagedPageMeta(),
)

@Serializable
internal data class ManagedPageMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    val total: Int = 0,
)

@Serializable
internal data class EventCity(val id: Long = 0, val name: String = "", val timezone: String = "Europe/Rome")

@Serializable
internal data class EventLinkRow(val label: String = "", val url: String = "")

@Serializable
internal data class EventFactRow(val label: String = "", val value: String = "")

@Serializable
internal data class EventLocation(
    val name: String? = null,
    val address: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
)

@Serializable
internal data class EventSeo(val title: String? = null, val description: String? = null, val image: String? = null)

@Serializable
internal data class EventTicketTier(
    val id: Long? = null,
    val name: String = "",
    val price: Double? = null,
    val status: String = "available",
    val url: String? = null,
    val note: String? = null,
)

/** Una serata in breve, nella scheda in lettura. */
@Serializable
internal data class ManagedEventDate(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("doors_at") val doorsAt: String? = null,
    val status: LabeledValue = LabeledValue(),
    val cancelled: Boolean = false,
    val place: String? = null,
    val lineup: List<String> = emptyList(),
    val capacity: Int? = null,
    @SerialName("capacity_left") val capacityLeft: Int? = null,
    @SerialName("status_note") val statusNote: String? = null,
    val highlight: String? = null,
)

@Serializable
internal data class ManagedEventDates(
    val upcoming: List<ManagedEventDate> = emptyList(),
    val past: List<ManagedEventDate> = emptyList(),
)

/** Le azioni possibili, calcolate dal server con le policy dei pannelli. */
@Serializable
internal data class EventAbilities(
    val update: Boolean = false,
    val delete: Boolean = false,
    val publish: Boolean = false,
    @SerialName("publish_needs_dates") val publishNeedsDates: Boolean = false,
    @SerialName("publish_directly") val publishDirectly: Boolean = false,
    val duplicate: Boolean = false,
    val schedule: Boolean = false,
    @SerialName("cancel_schedule") val cancelSchedule: Boolean = false,
    val submit: Boolean = false,
    val preview: Boolean = false,
    @SerialName("ticket_tiers") val ticketTiers: Boolean = false,
)

@Serializable
internal data class EventLinks(
    @SerialName("tickets_occurrence_id") val ticketsOccurrenceId: Long? = null,
    val statistics: Boolean = false,
    val social: Boolean = false,
    val sponsor: Boolean = false,
    val site: Boolean = false,
)

/** La scheda di un evento (`GET S/events/{id}`), con tutti i campi del modulo. */
@Serializable
internal data class ManagedEventDetail(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val source: EventSourceInfo = EventSourceInfo(),
    val poster: Poster? = null,
    @SerialName("next_starts_at") val nextStartsAt: String? = null,
    @SerialName("last_starts_at") val lastStartsAt: String? = null,
    val past: Boolean = false,
    val venue: NamedRef? = null,
    @SerialName("rejection_reason") val rejectionReason: String? = null,
    @SerialName("scheduled_publish_at") val scheduledPublishAt: String? = null,
    @SerialName("published_at") val publishedAt: String? = null,
    val city: EventCity = EventCity(),
    val category: NamedRef? = null,
    val organizer: NamedRef? = null,
    @SerialName("organizer_name") val organizerName: String? = null,
    @SerialName("organizer_url") val organizerUrl: String? = null,
    @SerialName("price_type") val priceType: LabeledValue = LabeledValue(),
    @SerialName("price_min") val priceMin: Double? = null,
    @SerialName("price_max") val priceMax: Double? = null,
    val description: String? = null,
    @SerialName("description_text") val descriptionText: String? = null,
    val tags: List<NamedRef> = emptyList(),
    @SerialName("ticket_url") val ticketUrl: String? = null,
    @SerialName("booking_url") val bookingUrl: String? = null,
    @SerialName("external_links") val externalLinks: List<EventLinkRow> = emptyList(),
    val facts: List<EventFactRow> = emptyList(),
    @SerialName("custom_location") val customLocation: EventLocation? = null,
    @SerialName("content_details") val contentDetails: JsonElement? = null,
    val seo: EventSeo = EventSeo(),
    @SerialName("ticket_tiers") val ticketTiers: List<EventTicketTier> = emptyList(),
    val dates: ManagedEventDates = ManagedEventDates(),
    @SerialName("public_url") val publicUrl: String? = null,
    val abilities: EventAbilities = EventAbilities(),
    val links: EventLinks = EventLinks(),
) {
    val details: JsonObject get() = contentDetails as? JsonObject ?: JsonObject(emptyMap())
}

/** Che cosa è successo salvando: `draft`, `published`, `pending`, `scheduled`. */
@Serializable
internal data class EventSaveMeta(
    val outcome: String? = null,
    @SerialName("sent_to_review") val sentToReview: Boolean = false,
    @SerialName("facebook_photo") val facebookPhoto: Boolean? = null,
)

@Serializable
internal data class EventSaveResponse(
    val data: ManagedEventDetail = ManagedEventDetail(),
    val meta: EventSaveMeta = EventSaveMeta(),
)

@Serializable
internal data class EventShortcut(
    val value: String = "",
    val label: String = "",
    @SerialName("starts_at") val startsAt: String = "",
    val repeats: Boolean = false,
    val weekday: String? = null,
)

@Serializable
internal data class EventFormVenue(val id: Long = 0, val name: String = "", val address: String? = null)

@Serializable
internal data class EventFormLimits(
    @SerialName("ticket_tiers") val ticketTiers: Int = 12,
    val tags: Int = 50,
    val agenda: Int = 50,
    val faqs: Int = 30,
)

/** Le scelte del modulo (`GET S/events/form`). */
@Serializable
internal data class EventFormOptions(
    val categories: List<NamedRef> = emptyList(),
    val city: EventCity = EventCity(),
    val cities: List<EventCity> = emptyList(),
    val defaults: JsonObject = JsonObject(emptyMap()),
    @SerialName("start_time") val startTime: String? = null,
    val shortcuts: List<EventShortcut> = emptyList(),
    val venue: EventFormVenue? = null,
    @SerialName("before_going") val beforeGoing: JsonElement? = null,
    @SerialName("ticket_tier_statuses") val ticketTierStatuses: List<LabeledValue> = emptyList(),
    val weekdays: List<LabeledValue> = emptyList(),
    @SerialName("attendance_modes") val attendanceModes: List<LabeledValue> = emptyList(),
    @SerialName("parking_types") val parkingTypes: List<LabeledValue> = emptyList(),
    @SerialName("organizer_types") val organizerTypes: List<LabeledValue> = emptyList(),
    val limits: EventFormLimits = EventFormLimits(),
) {
    fun defaultString(key: String): String? = (defaults[key] as? JsonPrimitive)?.contentOrNull
}

@Serializable
internal data class SameNightItem(
    val title: String = "",
    val place: String = "",
    val time: String = "",
    val category: String = "",
    @SerialName("same_category") val sameCategory: Boolean = false,
    @SerialName("distance_m") val distanceM: Int = 0,
    val url: String = "",
)

@Serializable
internal data class VenueClosureInfo(val from: String = "", val to: String = "", val note: String? = null)

/** «Stessa sera in zona» e chiusura straordinaria (`POST S/events/checks`). */
@Serializable
internal data class EventDateChecksResult(
    @SerialName("same_night") val sameNight: List<SameNightItem> = emptyList(),
    @SerialName("radius_km") val radiusKm: Double = 2.0,
    val closure: VenueClosureInfo? = null,
)

@Serializable
internal data class FacebookSource(
    @SerialName("venue_name") val venueName: String? = null,
    @SerialName("venue_address") val venueAddress: String? = null,
    @SerialName("date_label") val dateLabel: String? = null,
    val hosts: List<String> = emptyList(),
    @SerialName("responded_count") val respondedCount: Int? = null,
)

/** L'evento letto da Facebook: i campi del modulo e il gettone da rimandare con la creazione. */
@Serializable
internal data class FacebookImportResult(
    val fields: JsonObject = JsonObject(emptyMap()),
    val import: String = "",
    @SerialName("photo_url") val photoUrl: String? = null,
    val source: FacebookSource = FacebookSource(),
    @SerialName("location_address") val locationAddress: String? = null,
)

@Serializable
internal data class VenueLookup(val id: Long = 0, val name: String = "", val address: String? = null, val city: String? = null)

/** Il testo di una chiave di un oggetto JSON, o `null`. */
internal fun JsonObject.text(key: String): String? = (this[key] as? JsonPrimitive)?.contentOrNull

/** Un elenco di stringhe di un oggetto JSON (anche numeri), o vuoto. */
internal fun JsonObject.texts(key: String): List<String> =
    (this[key] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull } ?: emptyList()

/** Un elenco di oggetti di un oggetto JSON, o vuoto. */
internal fun JsonObject.objects(key: String): List<JsonObject> = (this[key] as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()
