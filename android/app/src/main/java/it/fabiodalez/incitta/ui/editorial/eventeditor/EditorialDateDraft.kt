package it.fabiodalez.incitta.ui.editorial.eventeditor

import it.fabiodalez.incitta.data.editorial.EditorDate
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

/*
 * Il modulo di una data della redazione (`OccurrencesRelationManager::form`)
 * come dato puro: informazioni pratiche e costi della data, locale della
 * data, orari nell'ora della città, stato, posti, evidenza, avviso al
 * pubblico, listino della sera, lineup.
 */

internal data class DateTierRow(val id: Long? = null, val name: String = "", val price: String = "", val status: String = "available", val url: String = "", val note: String = "")
internal data class LineupRow(val id: Long? = null, val name: String = "", val role: String = "live", val startsAt: LocalDateTime? = null, val url: String = "")

internal data class EditorialDateDraft(
    val venue: NamedRef? = null,
    val startsAt: LocalDateTime? = null,
    val endsAt: LocalDateTime? = null,
    val doorsAt: LocalDateTime? = null,
    val allDay: Boolean = false,
    val status: String = "scheduled",
    val capacity: String = "",
    val capacityLeft: String = "",
    val highlight: String = "",
    /** L'avviso al pubblico nei segni di `RichText`. */
    val statusNote: String = "",
    /** Le informazioni pratiche della data: `accessibility`, `membership` e le sei note. */
    val practical: Map<String, String> = emptyMap(),
    /** I costi dichiarati, chiave per chiave (`admission`, `drink`, `membership`, `other`). */
    val costs: Map<String, String> = emptyMap(),
    val tiers: List<DateTierRow> = emptyList(),
    val lineup: List<LineupRow> = emptyList(),
) {
    fun toJson(zone: ZoneId): JsonObject = buildJsonObject {
        put("venue_id", venue?.id?.let(::JsonPrimitive) ?: JsonNull)
        put("starts_at", startsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
        put("ends_at", endsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
        put("doors_at", doorsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
        put("is_all_day", allDay)
        put("status", status)
        put("capacity", number(capacity))
        put("capacity_left", number(capacityLeft))
        put("highlight", highlight.trim().ifBlank { null })
        put("status_note", RichText.toHtml(statusNote).ifBlank { null })
        put("practical_details", buildJsonObject {
            PRACTICAL_KEYS.forEach { key -> put(key, practical[key]?.trim()?.ifBlank { null }) }
        })
        put("cost_breakdown", buildJsonObject {
            COST_KEYS.forEach { key -> put(key, decimal(costs[key].orEmpty())) }
        })
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
        put("lineup", buildJsonArray {
            lineup.filter { it.name.isNotBlank() || it.id != null }.forEach { row ->
                add(buildJsonObject {
                    row.id?.let { put("id", it) }
                    put("name", row.name.trim())
                    put("role", row.role)
                    put("starts_at", row.startsAt?.let { JsonPrimitive(cityIso(it, zone)) } ?: JsonNull)
                    put("url", row.url.trim().ifBlank { null })
                })
            }
        })
    }

    companion object {
        val PRACTICAL_KEYS = listOf("accessibility", "membership", "entrance_notes", "membership_notes", "parking_notes", "transit_notes", "food_notes", "start_notes")
        val COST_KEYS = listOf("admission", "drink", "membership", "other")

        /** I campi del server che il modulo mostra. */
        val FIELD_KEYS = listOf(
            "venue_id", "starts_at", "ends_at", "doors_at", "is_all_day", "status", "capacity", "capacity_left", "highlight",
            "status_note", "practical_details", "cost_breakdown", "ticket_tiers", "lineup",
        )

        fun from(date: EditorDate): EditorialDateDraft {
            val zone = date.zone
            return EditorialDateDraft(
                venue = date.venue,
                startsAt = cityLocalDateTime(date.startsAt, zone),
                endsAt = cityLocalDateTime(date.endsAt, zone),
                doorsAt = cityLocalDateTime(date.doorsAt, zone),
                allDay = date.isAllDay,
                status = date.status.value.ifBlank { "scheduled" },
                capacity = date.capacity?.toString().orEmpty(),
                capacityLeft = date.capacityLeft?.toString().orEmpty(),
                highlight = date.highlight.orEmpty(),
                statusNote = RichText.toMarkup(date.statusNote),
                practical = texts(date.practicalDetails),
                costs = texts(date.costBreakdown),
                tiers = date.ticketTiers.map { DateTierRow(it.id, it.name, it.price?.let(::plain).orEmpty(), it.status, it.url.orEmpty(), it.note.orEmpty()) },
                lineup = date.lineup.map { LineupRow(it.id, it.name, it.role, cityLocalDateTime(it.startsAt, zone), it.url.orEmpty()) },
            )
        }

        private fun texts(element: JsonElement?): Map<String, String> =
            (element as? JsonObject)?.mapNotNull { (key, value) ->
                val primitive = runCatching { value.jsonPrimitive }.getOrNull() ?: return@mapNotNull null
                val text = primitive.doubleOrNull?.takeIf { !primitive.isString }?.let(::plain) ?: primitive.contentOrNull
                text?.let { key to it }
            }?.toMap().orEmpty()

        private fun plain(value: Double): String = if (value % 1.0 == 0.0) value.toLong().toString() else value.toString()
        private fun number(text: String): JsonElement = text.trim().toLongOrNull()?.let(::JsonPrimitive) ?: if (text.isBlank()) JsonNull else JsonPrimitive(text.trim())
        private fun decimal(text: String): JsonElement = text.replace(',', '.').trim().let { t -> t.toDoubleOrNull()?.let(::JsonPrimitive) ?: if (t.isEmpty()) JsonNull else JsonPrimitive(t) }
    }
}
