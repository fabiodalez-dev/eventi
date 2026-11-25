package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.BookingAnswer
import it.fabiodalez.incitta.data.BookingQuestion
import it.fabiodalez.incitta.data.DateCounts
import it.fabiodalez.incitta.ui.management.common.RichText
import java.io.File
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.put

/*
 * La biglietteria completa di una data (P3), come la pagina
 * `/gestione-biglietti/{data}` del sito: prenotazioni con stati e ricerca,
 * annullamento a nome del locale, impostazioni e domande, storico dei
 * messaggi, file da scaricare. Ogni campo ha un valore predefinito: un server
 * che ne manda meno non rompe la lettura.
 */

/** Gli stati dei filtri delle prenotazioni, nell'ordine del sito; `null` = tutte. */
internal val BOOKING_FILTERS = listOf("confirmed", "checked_in", "waitlisted", "cancelled")

/** Un biglietto di una prenotazione. `code` c'è solo finché il biglietto è valido: serve a «Registra ingresso». */
@Serializable internal data class ManagedTicket(
    val id: Long = 0,
    @SerialName("attendee_name") val attendeeName: String = "",
    @SerialName("first_name") val firstName: String? = null,
    @SerialName("last_name") val lastName: String? = null,
    val status: String = "valid",
    @SerialName("checked_in_at") val checkedInAt: String? = null,
    val code: String? = null,
) {
    /** Si può far entrare dalla riga: biglietto valido, con il codice. */
    val canCheckIn: Boolean get() = status == "valid" && !code.isNullOrBlank()
    /** Si può annullare: né annullato né già entrato (come i pulsanti del sito). */
    val canCancel: Boolean get() = status != "cancelled" && status != "checked_in"
}

/** Una prenotazione vista da chi gestisce la data. */
@Serializable internal data class ManagedBooking(
    val id: Long = 0,
    val status: String = "confirmed",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("staff_booking") val staffBooking: Boolean = false,
    val email: String? = null,
    /** I dati di chi prenota (telefono, indirizzo…) e, al banco, la nota. Le chiavi sono quelle del server. */
    val booker: JsonObject? = null,
    val answers: List<BookingAnswer> = emptyList(),
    @SerialName("privacy_accepted_at") val privacyAcceptedAt: String? = null,
    @SerialName("cancellation_reason") val cancellationReason: String? = null,
    @SerialName("tickets_count") val ticketsCount: Int = 0,
    val tickets: List<ManagedTicket> = emptyList(),
) {
    val cancelled: Boolean get() = status == "cancelled"
    /** I dati di chi prenota non vuoti, nell'ordine del server. */
    val bookerFields: List<Pair<String, String>> get() = booker.orEmpty().mapNotNull { (key, value) ->
        (value as? JsonPrimitive)?.contentOrNull?.trim()?.takeIf { it.isNotEmpty() }?.let { key to it }
    }
}

@Serializable internal data class BookingCounts(
    val all: Int = 0,
    val confirmed: Int = 0,
    @SerialName("checked_in") val checkedIn: Int = 0,
    val waitlisted: Int = 0,
    val cancelled: Int = 0,
) {
    fun of(status: String?): Int = when (status) {
        null -> all
        "confirmed" -> confirmed
        "checked_in" -> checkedIn
        "waitlisted" -> waitlisted
        "cancelled" -> cancelled
        else -> 0
    }
}

@Serializable internal data class BookingsMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    val status: String? = null,
    val counts: BookingCounts = BookingCounts(),
)

/** Una pagina di `GET management/dates/{id}/bookings`. */
@Serializable internal data class BookingsPage(val data: List<ManagedBooking> = emptyList(), val meta: BookingsMeta = BookingsMeta())

/** I tetti del modulo delle impostazioni, mandati dal server. */
@Serializable internal data class BookingSettingsLimits(
    @SerialName("booking_limit_max") val bookingLimitMax: Int = 20,
    val questions: Int = 5,
    @SerialName("question_options") val questionOptions: Int = 10,
    @SerialName("question_label_max") val questionLabelMax: Int = 160,
    @SerialName("instructions_max") val instructionsMax: Int = 3000,
)

/**
 * Le impostazioni delle prenotazioni come sono salvate. Le tre ore vuote
 * vogliono dire «subito» (apertura) e «all'inizio dell'evento» (chiusure).
 * `bookingInstructions` è il testo semplice delle istruzioni e
 * `bookingInstructionsRich` dice se sul sito hanno grassetto o elenchi:
 * il modulo parte dall'HTML salvato (`ManagedDate.availability.instructions`,
 * vedi `savedInstructions`) e lo riscrive con l'editor comune, quindi la
 * formattazione non si perde.
 */
@Serializable internal data class BookingSettings(
    @SerialName("booking_enabled") val bookingEnabled: Boolean = false,
    @SerialName("booking_capacity") val bookingCapacity: Int? = null,
    @SerialName("booking_limit") val bookingLimit: Int = 1,
    @SerialName("booking_waitlist") val bookingWaitlist: Boolean = false,
    @SerialName("booking_opens_at") val bookingOpensAt: String? = null,
    @SerialName("booking_closes_at") val bookingClosesAt: String? = null,
    @SerialName("cancellation_closes_at") val cancellationClosesAt: String? = null,
    @SerialName("booking_instructions") val bookingInstructions: String? = null,
    @SerialName("booking_instructions_rich") val bookingInstructionsRich: Boolean = false,
    @SerialName("booking_fields") val bookingFields: Map<String, String> = emptyMap(),
    @SerialName("booking_questions") val bookingQuestions: List<BookingQuestion> = emptyList(),
    val limits: BookingSettingsLimits = BookingSettingsLimits(),
)

/** I dati che si possono chiedere a chi prenota, nell'ordine del sito, e i tre modi. */
internal val BOOKER_FIELDS = listOf("phone", "address", "city", "postal_code", "country")
internal val BOOKER_FIELD_MODES = listOf("hidden", "optional", "required")
internal val QUESTION_TYPES = listOf("text", "choice", "yes_no")

/** Una domanda nel modulo: le scelte si scrivono una per riga, come sul sito. */
internal data class QuestionDraft(
    val id: String? = null,
    val label: String = "",
    val type: String = "text",
    val options: String = "",
    val required: Boolean = false,
)

/**
 * Il modulo delle impostazioni mentre lo si compila. Le ore sono quelle della
 * città (`LocalDateTime`), mai del telefono; i numeri restano testo finché
 * non si salva, così un errore del server finisce sotto il campo.
 */
internal data class BookingSettingsDraft(
    val enabled: Boolean = false,
    val capacity: String = "",
    val limit: String = "1",
    val waitlist: Boolean = false,
    val opensAt: java.time.LocalDateTime? = null,
    val closesAt: java.time.LocalDateTime? = null,
    val cancellationClosesAt: java.time.LocalDateTime? = null,
    val instructions: String = "",
    val fields: Map<String, String> = BOOKER_FIELDS.associateWith { "hidden" },
    val questions: List<QuestionDraft> = emptyList(),
) {
    companion object {
        /** `instructionsHtml`: le istruzioni salvate in HTML (`ManagedDate.availability.instructions`). */
        fun from(settings: BookingSettings, zone: java.time.ZoneId, instructionsHtml: String? = null): BookingSettingsDraft = BookingSettingsDraft(
            enabled = settings.bookingEnabled,
            capacity = settings.bookingCapacity?.toString().orEmpty(),
            limit = settings.bookingLimit.toString(),
            waitlist = settings.bookingWaitlist,
            opensAt = localIn(settings.bookingOpensAt, zone),
            closesAt = localIn(settings.bookingClosesAt, zone),
            cancellationClosesAt = localIn(settings.cancellationClosesAt, zone),
            instructions = savedInstructions(settings, instructionsHtml),
            fields = BOOKER_FIELDS.associateWith { key -> settings.bookingFields[key]?.takeIf { it in BOOKER_FIELD_MODES } ?: "hidden" },
            questions = settings.bookingQuestions.map { QuestionDraft(it.id, it.label, it.type, it.options.joinString(), it.required) },
        )

        private fun List<String>.joinString() = joinToString("\n")

        private fun localIn(iso: String?, zone: java.time.ZoneId): java.time.LocalDateTime? {
            if (iso.isNullOrBlank()) return null
            return runCatching { java.time.OffsetDateTime.parse(iso).atZoneSameInstant(zone).toLocalDateTime() }.getOrNull()
        }
    }
}

/**
 * Le istruzioni salvate come segni dell'editor comune (`RichText`): dall'HTML
 * della data quando il server lo manda, se no dal testo semplice delle
 * impostazioni (server di prima).
 */
internal fun savedInstructions(settings: BookingSettings?, html: String?): String =
    RichText.toMarkup(html?.takeIf { it.isNotBlank() } ?: settings?.bookingInstructions)

/**
 * Il corpo di `PATCH management/dates/{id}/settings`. Come il modulo del
 * sito si manda tutto, tranne le istruzioni se non sono cambiate: così un
 * testo scritto sul sito non viene riscritto solo perché si è cambiato il
 * numero di posti dall'app. Le istruzioni cambiate vanno come HTML, lo
 * stesso che salva l'editor del sito (il server lo ripulisce con
 * `Description::sanitize`). Un numero che non è un numero si manda così
 * com'è, e l'errore del server va sotto il campo.
 */
internal fun bookingSettingsBody(draft: BookingSettingsDraft, original: BookingSettings?, zone: java.time.ZoneId, originalHtml: String? = null): JsonObject = buildJsonObject {
    put("booking_enabled", draft.enabled)
    put("booking_capacity", numberOrRaw(draft.capacity))
    put("booking_limit", numberOrRaw(draft.limit))
    put("booking_waitlist", draft.waitlist)
    put("booking_opens_at", draft.opensAt?.let { JsonPrimitive(cityIsoOf(it, zone)) } ?: JsonNull)
    put("booking_closes_at", draft.closesAt?.let { JsonPrimitive(cityIsoOf(it, zone)) } ?: JsonNull)
    put("cancellation_closes_at", draft.cancellationClosesAt?.let { JsonPrimitive(cityIsoOf(it, zone)) } ?: JsonNull)
    if (draft.instructions.trim() != savedInstructions(original, originalHtml).trim()) {
        put("booking_instructions", RichText.toHtml(draft.instructions).takeIf { it.isNotEmpty() }?.let(::JsonPrimitive) ?: JsonNull)
    }
    put("booking_fields", buildJsonObject { BOOKER_FIELDS.forEach { key -> put(key, draft.fields[key] ?: "hidden") } })
    put("booking_questions", buildJsonArray {
        draft.questions.forEach { question ->
            add(buildJsonObject {
                question.id?.takeIf { it.isNotBlank() }?.let { put("id", it) }
                put("label", question.label.trim())
                put("type", question.type)
                if (question.type == "choice") put("options", question.options.lines().map(String::trim).filter(String::isNotEmpty).joinToString("\n"))
                put("required", question.required)
            })
        }
    })
}

private fun numberOrRaw(value: String): JsonElement {
    val text = value.trim()
    if (text.isEmpty()) return JsonNull
    return text.toLongOrNull()?.let(::JsonPrimitive) ?: JsonPrimitive(text)
}

private fun cityIsoOf(local: java.time.LocalDateTime, zone: java.time.ZoneId): String =
    local.atZone(zone).withEarlierOffsetAtOverlap().toOffsetDateTime().format(java.time.format.DateTimeFormatter.ISO_OFFSET_DATE_TIME)

/** Un messaggio già scritto a chi ha prenotato. */
@Serializable internal data class SentMessage(
    val id: Long = 0,
    val subject: String = "",
    val body: String = "",
    @SerialName("include_waitlisted") val includeWaitlisted: Boolean = false,
    @SerialName("recipients_count") val recipientsCount: Int = 0,
    val sender: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable internal data class MessageHistoryMeta(
    @SerialName("daily_limit") val dailyLimit: Int = 3,
    @SerialName("sent_last_24h") val sentLast24h: Int = 0,
)

/** `GET management/dates/{id}/messages`: gli ultimi dieci, i più recenti prima. */
@Serializable internal data class MessageHistory(val data: List<SentMessage> = emptyList(), val meta: MessageHistoryMeta = MessageHistoryMeta())

/** Una data nel riquadro «Ticketing inCittà» dell'evento. */
@Serializable internal data class EventTicketingDate(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    val venue: String? = null,
    @SerialName("booking_enabled") val bookingEnabled: Boolean = false,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    val ended: Boolean = false,
    val counts: DateCounts? = null,
)

/** Il riquadro «Ticketing inCittà» di un evento del locale (`GET V/events/{id}/ticketing`). */
@Serializable internal data class EventTicketing(
    @SerialName("event_id") val eventId: Long = 0,
    val title: String = "",
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    val timezone: String? = null,
    @SerialName("dates_count") val datesCount: Int = 0,
    val dates: List<EventTicketingDate> = emptyList(),
)

/** I due file della data, con i nomi che dà il sito. */
internal enum class ExportKind(val path: String, val mime: String) {
    Participants("export.csv", "text/csv"),
    EntryList("list.pdf", "application/pdf");

    fun fileName(dateId: Long): String = when (this) {
        Participants -> "partecipanti-$dateId.csv"
        EntryList -> "lista-ingressi-$dateId.pdf"
    }
}

/** Un file scaricato nella cache dell'app, pronto per «Apri con» o «Condividi». */
internal data class DownloadedFile(val file: File, val mime: String)
