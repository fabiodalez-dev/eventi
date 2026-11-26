package it.fabiodalez.incitta.ui.editorial.eventeditor

import it.fabiodalez.incitta.data.editorial.EditorCan
import it.fabiodalez.incitta.data.editorial.EditorEventOptions
import it.fabiodalez.incitta.data.editorial.EditorEventValues
import it.fabiodalez.incitta.data.management.ManagedEventDetail
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.events.EventForm
import it.fabiodalez.incitta.ui.management.events.EventFormMode
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject

/*
 * Il modulo evento della redazione come dato puro: si costruisce dalle
 * scelte (evento nuovo) o dalla scheda del server, si modifica nella
 * schermata e diventa il corpo di `POST`/`PATCH E/events`.
 *
 * I campi in comune con il modulo della gestione stanno in `base` (lo stesso
 * `EventForm`, con la stessa trasformazione in JSON: testo formattato, «Prima
 * di andare», informazioni utili, programma, domande, listino, scheda
 * tecnica, collegamenti, SEO); qui ci sono quelli che il pannello mostra
 * solo alla redazione.
 */

/** Una data del ripetitore «Date» della creazione, nell'ora della città. */
internal data class NewDateRow(
    val startsAt: LocalDateTime? = null,
    val endsAt: LocalDateTime? = null,
    val doorsAt: LocalDateTime? = null,
    val allDay: Boolean = false,
)

/** Una ricorrenza: la RRULE, «Fino al» nell'ora della città, le date escluse una per riga. */
internal data class RecurrenceRow(
    val id: Long? = null,
    val rrule: String = "",
    val until: LocalDateTime? = null,
    val exdates: String = "",
    val generatedUntil: String? = null,
)

/** Le schede del modulo, nell'ordine del pannello. */
internal enum class EditorTab { What, When, Where, Tickets, BeforeGoing, Content, Extra, Publish }

internal data class EditorialEventDraft(
    val base: EventForm = EventForm(),
    val status: String = "draft",
    val subtitle: String = "",
    val slug: String = "",
    val shortDescription: String = "",
    val ageRestriction: String = "",
    val language: String = "",
    val videoUrl: String = "",
    val isOutdoor: Boolean = false,
    val organizer: NamedRef? = null,
    val coOrganizers: List<NamedRef> = emptyList(),
    val priceNotes: String = "",
    val currency: String = "EUR",
    val bookingRequired: Boolean = false,
    val bookingPhone: String = "",
    val seoIndexing: String = "automatic",
    val isDemo: Boolean = false,
    val source: String = "manual",
    val verificationStatus: String = "unverified",
    val editorialScore: String = "0",
    val isFeatured: Boolean = false,
    val featuredUntil: LocalDateTime? = null,
    val rejectionReason: String = "",
    val newDates: List<NewDateRow> = listOf(NewDateRow()),
    val recurrences: List<RecurrenceRow> = emptyList(),
) {
    val hasAmount: Boolean get() = base.hasAmount

    /**
     * Il corpo della rotta. `zone` è il fuso della città dell'evento: le date
     * partono con il loro scarto. In creazione c'è il ripetitore «Date»;
     * evidenza, indicizzazione e dimostrativo solo se chi compila li può
     * scrivere (il pannello li spegne e non li salva).
     */
    fun toJson(zone: ZoneId, creating: Boolean, can: EditorCan): JsonObject {
        val core = base.toJson(EventFormMode.VenueEdit, zone)
        val body = core.toMutableMap()
        body["status"] = JsonPrimitive(status)
        body["subtitle"] = text(subtitle)
        body["slug"] = text(slug)
        body["short_description"] = text(shortDescription)
        body["age_restriction"] = text(ageRestriction)
        body["language"] = text(language)
        body["video_url"] = text(videoUrl)
        body["city_id"] = base.cityId?.let(::JsonPrimitive) ?: JsonNull
        body["venue_id"] = base.venue?.id?.let(::JsonPrimitive) ?: JsonNull
        body["is_outdoor"] = JsonPrimitive(isOutdoor)
        body["organizer_id"] = organizer?.id?.let(::JsonPrimitive) ?: JsonNull
        body["organizers"] = buildJsonArray { coOrganizers.forEach { add(JsonPrimitive(it.id)) } }
        body["price_notes"] = text(priceNotes)
        body["booking_required"] = JsonPrimitive(bookingRequired)
        if (bookingRequired) body["booking_phone"] = text(bookingPhone) else body.remove("booking_url")
        if (hasAmount) body["currency"] = JsonPrimitive(currency.trim()) else body.remove("ticket_tiers")
        body["source"] = JsonPrimitive(source)
        body["verification_status"] = JsonPrimitive(verificationStatus)
        body["editorial_score"] = editorialScore.trim().replace(',', '.').let { score ->
            score.toIntOrNull()?.let(::JsonPrimitive) ?: score.toDoubleOrNull()?.let(::JsonPrimitive) ?: if (score.isEmpty()) JsonNull else JsonPrimitive(score)
        }
        body["rejection_reason"] = text(rejectionReason)
        if (can.feature) {
            body["is_featured"] = JsonPrimitive(isFeatured)
            if (isFeatured) body["featured_until"] = featuredUntil?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull
        }
        val seo = (core["seo"] as? JsonObject)?.toMutableMap() ?: mutableMapOf()
        if (can.admin) {
            seo["indexing"] = JsonPrimitive(seoIndexing)
            body["is_demo"] = JsonPrimitive(isDemo)
        }
        body["seo"] = JsonObject(seo)
        // Con un locale il luogo libero non si mostra e non si salva.
        if (base.venue != null) body.remove("custom_location")
        body["recurrences"] = buildJsonArray {
            recurrences.filter { it.rrule.isNotBlank() || it.id != null }.forEach { row ->
                add(buildJsonObject {
                    row.id?.let { put("id", JsonPrimitive(it)) }
                    put("rrule", JsonPrimitive(row.rrule.trim()))
                    put("until", row.until?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
                    put("exdates", buildJsonArray { row.exdates.lines().map(String::trim).filter(String::isNotEmpty).forEach { add(JsonPrimitive(it)) } })
                })
            }
        }
        if (creating) {
            body["occurrences"] = buildJsonArray {
                newDates.forEach { row ->
                    add(buildJsonObject {
                        put("starts_at", row.startsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
                        put("ends_at", row.endsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
                        put("doors_at", row.doorsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
                        put("is_all_day", JsonPrimitive(row.allDay))
                    })
                }
            }
        }
        return JsonObject(body)
    }

    companion object {
        private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; coerceInputValues = true }

        /** I campi del server che il modulo mostra: gli errori di quelli vanno sotto il campo, gli altri in cima. */
        val FIELD_KEYS = listOf(
            "status", "title", "subtitle", "slug", "short_description", "description", "category_id", "tags", "age_restriction", "language",
            "video_url", "occurrences", "recurrences", "city_id", "venue_id", "custom_location", "is_outdoor", "organizer_id", "organizers",
            "organizer_name", "organizer_url", "price_type", "price_min", "price_max", "price_notes", "currency", "ticket_url",
            "booking_required", "booking_url", "booking_phone", "ticket_tiers", "content_details.online_url", "content_details.minimum_age",
            "content_details.agenda", "content_details.faqs", "content_details", "facts", "external_links", "seo",
            "is_demo", "source", "verification_status", "editorial_score", "is_featured", "featured_until", "rejection_reason",
        )

        /** Un evento nuovo con i predefiniti del pannello. */
        fun blank(options: EditorEventOptions): EditorialEventDraft {
            val defaults = options.defaults
            return EditorialEventDraft(
                base = EventForm(categoryId = null, priceType = defaults.priceType, cityId = defaults.cityId),
                status = defaults.status,
                currency = defaults.currency,
                source = defaults.source,
                verificationStatus = defaults.verificationStatus,
                editorialScore = defaults.editorialScore.toString(),
            )
        }

        /** Il modulo riaperto sulla scheda del server (`event` di `GET E/events/{id}/form`). */
        fun from(event: JsonObject): Pair<EditorialEventDraft, EditorEventValues> {
            val values = json.decodeFromJsonElement(EditorEventValues.serializer(), event)
            val detail = json.decodeFromJsonElement(ManagedEventDetail.serializer(), event)
            val zone = runCatching { ZoneId.of(values.city.timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
            val draft = EditorialEventDraft(
                base = EventForm.from(detail),
                status = values.status.value.ifBlank { "draft" },
                subtitle = values.subtitle.orEmpty(),
                slug = values.slug,
                shortDescription = values.shortDescription.orEmpty(),
                ageRestriction = values.ageRestriction.orEmpty(),
                language = values.language.orEmpty(),
                videoUrl = values.videoUrl.orEmpty(),
                isOutdoor = values.isOutdoor,
                organizer = values.organizer,
                coOrganizers = values.organizers,
                priceNotes = values.priceNotes.orEmpty(),
                currency = values.currency.ifBlank { "EUR" },
                bookingRequired = values.bookingRequired,
                bookingPhone = values.bookingPhone.orEmpty(),
                seoIndexing = values.seo.indexing,
                isDemo = values.isDemo,
                source = values.source.value.ifBlank { "manual" },
                verificationStatus = values.verificationStatus,
                editorialScore = values.editorialScore.toString(),
                isFeatured = values.isFeatured,
                featuredUntil = cityLocalDateTime(values.featuredUntil, zone),
                rejectionReason = values.rejectionReason.orEmpty(),
                newDates = emptyList(),
                recurrences = values.recurrences.map { RecurrenceRow(it.id, it.rrule, cityLocalDateTime(it.until, zone), it.exdates.joinToString("\n"), it.generatedUntil) },
            )
            return draft to values
        }

        /** «Prima di andare» ricalcolato dal server dopo il cambio di locale. */
        fun withDetails(draft: EditorialEventDraft, details: JsonObject): EditorialEventDraft = draft.copy(base = draft.base.withBeforeGoing(details))

        /** `content_details` del modulo, da mandare al ricalcolo quando cambia il locale. */
        fun details(draft: EditorialEventDraft, zone: ZoneId): JsonObject =
            draft.base.toJson(EventFormMode.VenueEdit, zone)["content_details"] as? JsonObject ?: JsonObject(emptyMap())

        private fun text(value: String): JsonElement = value.trim().ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull
    }
}
