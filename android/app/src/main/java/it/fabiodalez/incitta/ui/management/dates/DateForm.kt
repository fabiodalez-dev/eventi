package it.fabiodalez.incitta.ui.management.dates

import it.fabiodalez.incitta.data.management.ManagedEventOccurrence
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import java.math.BigDecimal
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.put

/*
 * Il modulo di una data come dato puro, nelle due varianti dei pannelli:
 * il locale (inizio, fine, posti, evidenza, lineup) e l'organizzazione
 * («Date e luoghi»: inizio, fine, tutto il giorno, locale della data, stato
 * fra i cinque, avviso al pubblico). In entrambe informazioni pratiche e
 * costi della data. Si costruisce dalla riga del server e diventa il corpo
 * JSON della rotta; gli orari sono sempre l'ora della città.
 */

internal data class LineupRow(val id: Long? = null, val name: String = "", val role: String = "live")

/** Le chiavi delle informazioni pratiche e dei costi, come `OccurrenceDecisionFields`. */
internal val PRACTICAL_NOTE_KEYS = listOf("entrance_notes", "membership_notes", "parking_notes", "transit_notes", "food_notes", "start_notes")
internal val PRACTICAL_KEYS = listOf("accessibility", "membership") + PRACTICAL_NOTE_KEYS
internal val COST_KEYS = listOf("admission", "drink", "membership", "other")

internal data class DateForm(
    val startsAt: LocalDateTime? = null,
    val endsAt: LocalDateTime? = null,
    /* Locale */
    val capacity: String = "",
    val capacityLeft: String = "",
    val highlight: String = "",
    val lineup: List<LineupRow> = emptyList(),
    /* Organizzazione */
    val isAllDay: Boolean = false,
    val venue: NamedRef? = null,
    val status: String = "scheduled",
    val statusNote: String = "",
    /* Entrambi: vuoto = «usa quello dell'evento o del locale» */
    val practical: Map<String, String> = emptyMap(),
    val costs: Map<String, String> = emptyMap(),
) {
    /** Gli errori che si vedono prima di mandare: inizio mancante, fine prima dell'inizio, costi scritti male. */
    fun localErrors(): Set<String> = buildSet {
        if (startsAt == null) add("starts_at")
        if (startsAt != null && endsAt != null && !endsAt.isAfter(startsAt)) add("ends_at")
        if (!validNumber(capacity)) add("capacity")
        if (!validNumber(capacityLeft)) add("capacity_left")
        if (costs.values.any { !validCost(it) }) add("cost_breakdown")
    }

    /**
     * Il corpo per `POST`/`PATCH S/events/{id}/dates…`. Informazioni pratiche
     * e costi si mandano sempre per intero: il server sostituisce le due
     * parti, e un campo vuoto (`null`) vuol dire «eredita».
     */
    fun toJson(kind: SubjectKind, zone: ZoneId): JsonObject = buildJsonObject {
        startsAt?.let { put("starts_at", cityIso(it, zone)) }
        put("ends_at", endsAt?.let { cityIso(it, zone) })
        when (kind) {
            SubjectKind.Venue -> {
                put("capacity", capacity.trim().toIntOrNull())
                put("capacity_left", capacityLeft.trim().toIntOrNull())
                put("highlight", highlight.trim().ifBlank { null })
                put("lineup", buildJsonArray {
                    lineup.filter { it.name.isNotBlank() }.forEach { row ->
                        add(buildJsonObject {
                            row.id?.let { put("id", it) }
                            put("name", row.name.trim())
                            put("role", row.role)
                        })
                    }
                })
            }
            SubjectKind.Organizer -> {
                put("is_all_day", isAllDay)
                put("venue_id", venue?.id)
                put("status", status)
                put("status_note", RichText.toHtml(statusNote).ifBlank { null })
            }
        }
        put("practical_details", buildJsonObject {
            PRACTICAL_KEYS.forEach { key -> put(key, practical[key]?.trim()?.ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull) }
        })
        put("cost_breakdown", buildJsonObject {
            COST_KEYS.forEach { key -> put(key, costs[key]?.trim()?.ifBlank { null }?.replace(',', '.')?.let(::JsonPrimitive) ?: JsonNull) }
        })
    }

    companion object {
        /** Il modulo riaperto su una data salvata, con gli orari nella città. */
        fun from(date: ManagedEventOccurrence, zone: ZoneId): DateForm = DateForm(
            startsAt = cityLocalDateTime(date.startsAt, zone),
            endsAt = cityLocalDateTime(date.endsAt, zone),
            capacity = date.capacity?.toString().orEmpty(),
            capacityLeft = date.capacityLeft?.toString().orEmpty(),
            highlight = date.highlight.orEmpty(),
            lineup = date.lineup.map { LineupRow(it.id, it.name, it.role.value.ifBlank { "live" }) },
            isAllDay = date.isAllDay,
            venue = date.venue,
            status = date.status.value.ifBlank { "scheduled" },
            statusNote = RichText.toMarkup(date.statusNote),
            practical = texts(date.practicalDetails),
            costs = texts(date.costBreakdown).mapValues { (_, value) -> plainAmount(value) },
        )

        private fun texts(source: JsonObject): Map<String, String> =
            source.mapNotNull { (key, value) -> (value as? JsonPrimitive)?.contentOrNull?.let { key to it } }.toMap()

        /** «8.50» resta com'è, «8» da «8.0»: quello che la persona ha scritto, senza zeri in più. */
        private fun plainAmount(value: String): String =
            value.toBigDecimalOrNull()?.stripTrailingZeros()?.let { if (it.scale() < 0) it.setScale(0) else it }?.toPlainString() ?: value

        fun validNumber(value: String): Boolean = value.isBlank() || value.trim().toIntOrNull()?.let { it in 0..1_000_000 } == true

        /** Da zero a 100.000, con al massimo due decimali (virgola o punto). */
        fun validCost(value: String): Boolean = value.isBlank() ||
            (value.trim().matches(Regex("\\d{1,6}([.,]\\d{1,2})?")) && value.trim().replace(',', '.').toBigDecimal() <= BigDecimal("100000"))
    }
}
