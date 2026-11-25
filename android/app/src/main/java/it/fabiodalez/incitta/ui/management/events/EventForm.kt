package it.fabiodalez.incitta.ui.management.events

import it.fabiodalez.incitta.data.management.EventFormOptions
import it.fabiodalez.incitta.data.management.ManagedEventDetail
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.objects
import it.fabiodalez.incitta.data.management.text
import it.fabiodalez.incitta.data.management.texts
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put

/*
 * Il modulo dell'evento come dato puro: si costruisce dalla scheda del server
 * (o vuoto, con le abitudini del locale), si modifica nella schermata e si
 * trasforma nel corpo JSON delle rotte. Tre varianti, come nei pannelli:
 * creazione del locale (pagina sola con l'essenziale in cima), modifica del
 * locale (sezioni richiudibili), organizzazione (modulo unico).
 *
 * I testi formattati stanno qui nella forma di `RichText` (i segni) e
 * diventano HTML solo nel corpo da mandare.
 */

/** Quale modulo: decide i campi mostrati e le chiavi mandate. */
internal enum class EventFormMode { VenueCreate, VenueEdit, OrganizerCreate, OrganizerEdit;
    val isVenue: Boolean get() = this == VenueCreate || this == VenueEdit
    val isCreate: Boolean get() = this == VenueCreate || this == OrganizerCreate

    companion object {
        fun of(kind: SubjectKind, create: Boolean): EventFormMode = when (kind) {
            SubjectKind.Venue -> if (create) VenueCreate else VenueEdit
            SubjectKind.Organizer -> if (create) OrganizerCreate else OrganizerEdit
        }
    }
}

internal data class LinkRow(val label: String = "", val url: String = "")
internal data class FactRow(val label: String = "", val value: String = "")
internal data class TierRow(val id: Long? = null, val name: String = "", val price: String = "", val status: String = "available", val url: String = "", val note: String = "")
internal data class PracticalRow(val label: String = "", val icon: String = "check-circle", val text: String = "")
/*
 * Una voce del programma. Inizio e fine stanno come li salva il pannello
 * (`AgendaTime`), la data collegata è l'id di una data dell'evento (vuota:
 * tutte le date); `extra` conserva le chiavi che l'app non conosce.
 */
internal data class AgendaRow(
    val title: String = "",
    val whenText: String = "",
    val speaker: String = "",
    val description: String = "",
    val startsAt: String? = null,
    val endsAt: String? = null,
    val occurrenceId: Long? = null,
    val extra: JsonObject = JsonObject(emptyMap()),
)

/**
 * Inizio e fine di una voce del programma: il pannello li salva in UTC come
 * `2026-09-03 18:00:00` (il fuso dell'applicazione) e li mostra nell'ora di
 * casa. L'app li legge e li scrive nell'ora della città, e li rimanda nello
 * stesso formato; un valore con lo scarto vale da sé.
 */
internal object AgendaTime {
    private val stored = java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")

    fun toCity(raw: String?, zone: ZoneId): LocalDateTime? {
        if (raw.isNullOrBlank()) return null
        cityLocalDateTime(raw, zone)?.let { return it }
        val text = raw.trim().replace('T', ' ')
        val local = runCatching { LocalDateTime.parse(if (text.length == 16) "$text:00" else text.take(19), stored) }.getOrNull() ?: return null
        return local.atZone(java.time.ZoneOffset.UTC).withZoneSameInstant(zone).toLocalDateTime()
    }

    fun fromCity(local: LocalDateTime?, zone: ZoneId): String? =
        local?.atZone(zone)?.withEarlierOffsetAtOverlap()?.withZoneSameInstant(java.time.ZoneOffset.UTC)?.toLocalDateTime()?.format(stored)
}
internal data class FaqRow(val question: String = "", val answer: String = "")

internal data class RepeatState(
    val enabled: Boolean = false,
    val frequency: String = "weekly",
    val weekdays: Set<String> = emptySet(),
    val until: LocalDate? = null,
)

internal data class EventForm(
    val title: String = "",
    val description: String = "",
    val categoryId: Long? = null,
    val priceType: String = "free",
    val priceMin: String = "",
    val priceMax: String = "",
    val tags: List<NamedRef> = emptyList(),
    val ticketUrl: String = "",
    val bookingUrl: String = "",
    val externalLinks: List<LinkRow> = emptyList(),
    val locationName: String = "",
    val locationAddress: String = "",
    val locationLat: String = "",
    val locationLng: String = "",
    val startsAt: LocalDateTime? = null,
    val endsAt: LocalDateTime? = null,
    val repeat: RepeatState = RepeatState(),
    val cityId: Long? = null,
    val venue: NamedRef? = null,
    /* «Prima di andare» */
    val ageGroups: Set<String> = emptySet(),
    val stroller: String = "",
    val changingTable: String = "",
    val kidsArea: String = "",
    val membership: String = "",
    val membershipNotes: String = "",
    val accessibility: String = "",
    val accessibilityNotes: String = "",
    val featureIds: Set<Long> = emptySet(),
    val practical: List<PracticalRow> = emptyList(),
    val foodNotes: String = "",
    val startNotes: String = "",
    /* Solo in modifica del locale */
    val tiers: List<TierRow> = emptyList(),
    val facts: List<FactRow> = emptyList(),
    val organizerName: String = "",
    val organizerUrl: String = "",
    val seoTitle: String = "",
    val seoDescription: String = "",
    val seoImage: String = "",
    /* Informazioni editoriali (modifica del locale, organizzazione) */
    val editorial: Map<String, String> = emptyMap(),
    val organizerVenue: NamedRef? = null,
    val agenda: List<AgendaRow> = emptyList(),
    val faqs: List<FaqRow> = emptyList(),
) {
    val hasAmount: Boolean get() = priceType == "ticket" || priceType == "membership"

    /**
     * Il corpo per la rotta. `zone` è il fuso della città: le date partono
     * con il loro scarto. In creazione del locale `publish` dice quale dei
     * due pulsanti; `draftId` riprende la bozza già salvata.
     */
    fun toJson(mode: EventFormMode, zone: ZoneId, publish: Boolean = false, draftId: Long? = null, facebookImport: String? = null): JsonObject = buildJsonObject {
        put("title", title.trim())
        put("description", RichText.toHtml(description).ifBlank { null })
        categoryId?.let { put("category_id", it) }
        put("price_type", priceType)
        if (hasAmount) {
            put("price_min", decimal(priceMin))
            put("price_max", decimal(priceMax))
        }
        put("tags", buildJsonArray { tags.forEach { add(JsonPrimitive(it.id)) } })
        put("ticket_url", ticketUrl.trim().ifBlank { null })
        put("content_details", details(mode))

        if (mode.isVenue) {
            put("booking_url", bookingUrl.trim().ifBlank { null })
            put("external_links", buildJsonArray {
                externalLinks.filter { it.label.isNotBlank() || it.url.isNotBlank() }.forEach { row ->
                    add(buildJsonObject { put("label", row.label.trim()); put("url", row.url.trim()) })
                }
            })
        }
        if (mode != EventFormMode.OrganizerEdit) put("custom_location", location())
        if (mode.isCreate) {
            startsAt?.let { put("starts_at", cityIso(it, zone)) }
            endsAt?.let { put("ends_at", cityIso(it, zone)) }
            facebookImport?.takeIf { it.isNotBlank() }?.let { put("facebook_import", it) }
        }
        if (mode == EventFormMode.VenueCreate) {
            put("publish", publish)
            draftId?.let { put("draft_id", it) }
            if (repeat.enabled) {
                put("repeat", buildJsonObject {
                    put("frequency", repeat.frequency)
                    put("weekdays", buildJsonArray { if (repeat.frequency != "daily" && repeat.frequency != "monthly") repeat.weekdays.forEach { add(JsonPrimitive(it)) } })
                    repeat.until?.let { put("until", it.toString()) }
                })
            }
        }
        if (mode == EventFormMode.VenueEdit) {
            put("ticket_tiers", buildJsonArray {
                tiers.forEach { tier ->
                    add(buildJsonObject {
                        tier.id?.let { put("id", it) }
                        put("name", tier.name.trim())
                        put("price", decimal(tier.price))
                        put("status", tier.status)
                        put("url", tier.url.trim().ifBlank { null })
                        put("note", tier.note.trim().ifBlank { null })
                    })
                }
            })
            put("facts", buildJsonArray {
                facts.filter { it.label.isNotBlank() || it.value.isNotBlank() }.forEach { row ->
                    add(buildJsonObject { put("label", row.label.trim()); put("value", row.value.trim()) })
                }
            })
            put("organizer_name", organizerName.trim().ifBlank { null })
            put("organizer_url", organizerUrl.trim().ifBlank { null })
            put("seo", buildJsonObject {
                put("title", seoTitle.trim().ifBlank { null })
                put("description", seoDescription.trim().ifBlank { null })
                put("image", seoImage.trim().ifBlank { null })
            })
        }
        if (!mode.isVenue) {
            cityId?.let { put("city_id", it) }
            venue?.let { put("venue_id", it.id) }
        }
    }

    private fun location(): JsonElement = if (locationAddress.isBlank()) JsonNull else buildJsonObject {
        put("name", locationName.trim().ifBlank { null })
        put("address", locationAddress.trim())
        put("lat", locationLat.replace(',', '.').trim().toDoubleOrNull())
        put("lng", locationLng.replace(',', '.').trim().toDoubleOrNull())
    }

    /** `content_details`: «Prima di andare» sempre; le informazioni editoriali dove il pannello le mostra. */
    private fun details(mode: EventFormMode): JsonObject = buildJsonObject {
        put("age_groups", buildJsonArray { ageGroups.forEach { add(JsonPrimitive(it)) } })
        put("stroller", stroller.ifBlank { null })
        put("changing_table", changingTable.ifBlank { null })
        put("kids_area", kidsArea.ifBlank { null })
        put("membership", membership.ifBlank { null })
        put("membership_notes", RichText.toHtml(membershipNotes).ifBlank { null })
        put("accessibility", accessibility.ifBlank { null })
        put("accessibility_notes", RichText.toHtml(accessibilityNotes).ifBlank { null })
        put("feature_ids", buildJsonArray { featureIds.forEach { add(JsonPrimitive(it)) } })
        put("practical_custom", buildJsonArray {
            practical.filter { it.label.isNotBlank() }.forEach { row ->
                add(buildJsonObject { put("label", row.label.trim()); put("icon", row.icon); put("text", RichText.toHtml(row.text).ifBlank { null }) })
            }
        })
        put("food_notes", foodNotes.trim().ifBlank { null })
        put("start_notes", startNotes.trim().ifBlank { null })
        if (mode == EventFormMode.VenueEdit || !mode.isVenue) {
            EDITORIAL_TEXT_KEYS.forEach { key ->
                val value = editorial[key].orEmpty()
                put(key, (if (key in EDITORIAL_PLAIN_KEYS) value.trim() else RichText.toHtml(value)).ifBlank { null })
            }
            put("parking_type", editorial["parking_type"]?.ifBlank { null })
            put("attendance_mode", editorial["attendance_mode"]?.ifBlank { null })
            put("organizer_type", editorial["organizer_type"]?.ifBlank { null })
            put("online_url", editorial["online_url"]?.trim()?.ifBlank { null })
            put("minimum_age", editorial["minimum_age"]?.trim()?.toIntOrNull())
            put("organizer_venue_id", organizerVenue?.id)
            put("agenda", buildJsonArray {
                agenda.filter { it.title.isNotBlank() || it.whenText.isNotBlank() }.forEach { row ->
                    add(JsonObject(row.extra + mapOf(
                        "title" to JsonPrimitive(row.title.trim()),
                        "when" to JsonPrimitive(row.whenText.trim()),
                        "starts_at" to (row.startsAt?.ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull),
                        "ends_at" to (row.endsAt?.ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull),
                        "occurrence_id" to (row.occurrenceId?.let(::JsonPrimitive) ?: JsonNull),
                        "speaker" to (row.speaker.trim().ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull),
                        "description" to (RichText.toHtml(row.description).ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull),
                    )))
                }
            })
            put("faqs", buildJsonArray {
                faqs.filter { it.question.isNotBlank() }.forEach { row ->
                    add(buildJsonObject { put("question", row.question.trim()); put("answer", RichText.toHtml(row.answer)) })
                }
            })
        }
    }

    /** I campi letti da Facebook sopra quelli già scritti (come `form->fill` del pannello). */
    fun withFacebook(fields: JsonObject, zone: ZoneId): EventForm {
        var next = this
        fields.text("title")?.let { next = next.copy(title = it) }
        fields.text("description")?.let { next = next.copy(description = RichText.toMarkup(it)) }
        fields.text("price_type")?.let { next = next.copy(priceType = it) }
        if (fields.containsKey("price_min")) next = next.copy(priceMin = number(fields["price_min"]))
        if (fields.containsKey("price_max")) next = next.copy(priceMax = number(fields["price_max"]))
        fields.text("ticket_url")?.let { next = next.copy(ticketUrl = it) }
        cityLocalDateTime(fields.text("starts_at"), zone)?.let { next = next.copy(startsAt = it) }
        cityLocalDateTime(fields.text("ends_at"), zone)?.let { next = next.copy(endsAt = it) }
        (fields["city_id"] as? JsonPrimitive)?.longOrNull?.let { next = next.copy(cityId = it) }
        (fields["custom_location"] as? JsonObject)?.let { place ->
            next = next.copy(
                locationName = place.text("name").orEmpty(), locationAddress = place.text("address").orEmpty(),
                locationLat = number(place["lat"]), locationLng = number(place["lng"]),
            )
        }
        if (fields["external_links"] is JsonArray) {
            next = next.copy(externalLinks = fields.objects("external_links").map { LinkRow(it.text("label").orEmpty(), it.text("url").orEmpty()) })
        }
        return next
    }

    companion object {
        /** I testi editoriali, nell'ordine del modulo del pannello. */
        val EDITORIAL_TEXT_KEYS = listOf(
            "introduction", "parking_notes", "transit_notes", "entrance_notes", "mandatory_costs", "weather_policy",
            "minors_policy", "cancellation_policy", "refund_policy", "public_contact", "poster_alt", "poster_caption", "poster_credit",
        )
        /** Il testo alternativo della locandina è semplice, come nel pannello. */
        val EDITORIAL_PLAIN_KEYS = setOf("poster_alt")

        /** Un modulo nuovo con le abitudini del locale e «Prima di andare» già compilato. */
        fun blank(options: EventFormOptions, mode: EventFormMode): EventForm {
            val base = EventForm(
                categoryId = options.defaultString("category_id")?.toLongOrNull(),
                priceType = options.defaultString("price_type") ?: "free",
                priceMin = options.defaultString("price_min").orEmpty(),
                cityId = if (mode.isVenue) null else options.city.id.takeIf { it > 0 },
            )
            return base.withBeforeGoing(options.beforeGoing as? JsonObject ?: JsonObject(emptyMap()))
        }

        /** Il modulo riaperto su un evento salvato. */
        fun from(detail: ManagedEventDetail): EventForm {
            val details = detail.details
            val location = detail.customLocation
            return EventForm(
                title = detail.title,
                description = RichText.toMarkup(detail.description),
                categoryId = detail.category?.id,
                priceType = detail.priceType.value.ifBlank { "free" },
                priceMin = detail.priceMin?.let(::plainNumber).orEmpty(),
                priceMax = detail.priceMax?.let(::plainNumber).orEmpty(),
                tags = detail.tags,
                ticketUrl = detail.ticketUrl.orEmpty(),
                bookingUrl = detail.bookingUrl.orEmpty(),
                externalLinks = detail.externalLinks.map { LinkRow(it.label, it.url) },
                locationName = location?.name.orEmpty(),
                locationAddress = location?.address.orEmpty(),
                locationLat = location?.lat?.toString().orEmpty(),
                locationLng = location?.lng?.toString().orEmpty(),
                cityId = detail.city.id.takeIf { it > 0 },
                venue = detail.venue,
                tiers = detail.ticketTiers.map { TierRow(it.id, it.name, it.price?.let(::plainNumber).orEmpty(), it.status, it.url.orEmpty(), it.note.orEmpty()) },
                facts = detail.facts.map { FactRow(it.label, it.value) },
                organizerName = detail.organizerName.orEmpty(),
                organizerUrl = detail.organizerUrl.orEmpty(),
                seoTitle = detail.seo.title.orEmpty(),
                seoDescription = detail.seo.description.orEmpty(),
                seoImage = detail.seo.image.orEmpty(),
                editorial = buildMap {
                    EDITORIAL_TEXT_KEYS.forEach { key -> put(key, details.text(key)?.let { if (key in EDITORIAL_PLAIN_KEYS) it else RichText.toMarkup(it) }.orEmpty()) }
                    listOf("parking_type", "attendance_mode", "organizer_type", "online_url").forEach { key -> put(key, details.text(key).orEmpty()) }
                    put("minimum_age", details.text("minimum_age").orEmpty())
                },
                organizerVenue = (details["organizer_venue_id"] as? JsonPrimitive)?.longOrNull?.let { NamedRef(it, "") },
                agenda = details.objects("agenda").map { item ->
                    AgendaRow(item.text("title").orEmpty(), item.text("when").orEmpty(), item.text("speaker").orEmpty(), RichText.toMarkup(item.text("description")),
                        startsAt = item.text("starts_at"), endsAt = item.text("ends_at"), occurrenceId = item.text("occurrence_id")?.toLongOrNull(),
                        extra = JsonObject(item - setOf("title", "when", "speaker", "description", "starts_at", "ends_at", "occurrence_id")))
                },
                faqs = details.objects("faqs").map { FaqRow(it.text("question").orEmpty(), RichText.toMarkup(it.text("answer"))) },
            ).withBeforeGoing(details)
        }

        private fun plainNumber(value: Double): String = if (value % 1.0 == 0.0) value.toLong().toString() else value.toString()

        private fun number(element: JsonElement?): String = (element as? JsonPrimitive)?.let { it.doubleOrNull?.let(::plainNumber) ?: it.contentOrNull }.orEmpty()
    }

    /** «Prima di andare» da un oggetto `content_details` (dell'evento o del locale). */
    fun withBeforeGoing(details: JsonObject): EventForm = copy(
        ageGroups = details.texts("age_groups").toSet(),
        stroller = details.text("stroller").orEmpty(),
        changingTable = details.text("changing_table").orEmpty(),
        kidsArea = details.text("kids_area").orEmpty(),
        membership = details.text("membership").orEmpty(),
        membershipNotes = RichText.toMarkup(details.text("membership_notes")),
        accessibility = details.text("accessibility").orEmpty(),
        accessibilityNotes = RichText.toMarkup(details.text("accessibility_notes")),
        featureIds = details.texts("feature_ids").mapNotNull { it.toLongOrNull() }.toSet(),
        practical = details.objects("practical_custom").map { PracticalRow(it.text("label").orEmpty(), it.text("icon") ?: "check-circle", RichText.toMarkup(it.text("text"))) },
        foodNotes = details.text("food_notes").orEmpty(),
        startNotes = details.text("start_notes").orEmpty(),
    )
}

private fun decimal(text: String): JsonElement = text.replace(',', '.').trim().toDoubleOrNull()?.let(::JsonPrimitive) ?: JsonNull
