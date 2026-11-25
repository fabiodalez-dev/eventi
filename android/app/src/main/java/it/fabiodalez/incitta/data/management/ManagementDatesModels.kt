package it.fabiodalez.incitta.data.management

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

/*
 * Le date di un evento nella gestione (P2): le righe dell'elenco con i
 * campi dei due moduli, il quadro della schermata (evento, ripetizione,
 * azioni possibili) e le risposte delle scritture. Ogni campo ha un valore
 * predefinito: un server più vecchio o più nuovo non fa fallire la lettura.
 */

/** La prossima data di un evento, sulla card dell'elenco. */
@Serializable
internal data class ManagedNextDate(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    val status: LabeledValue = LabeledValue(),
)

@Serializable
internal data class DateLineupArtist(val id: Long? = null, val name: String = "", val role: LabeledValue = LabeledValue())

@Serializable
internal data class DateStats(val views: Long = 0, val clicks: Long = 0, val saves: Long = 0)

/** I gesti possibili su una data, decisi dal server con le policy dei pannelli. */
@Serializable
internal data class DateAbilities(
    val update: Boolean = false,
    val delete: Boolean = false,
    val cancel: Boolean = false,
    @SerialName("sold_out") val soldOut: Boolean = false,
)

/** Una data (`GET S/events/{id}/dates`), con tutti i campi dei due moduli. */
@Serializable
internal data class ManagedEventOccurrence(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("doors_at") val doorsAt: String? = null,
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    @SerialName("business_date") val businessDate: String? = null,
    val past: Boolean = false,
    val status: LabeledValue = LabeledValue(),
    @SerialName("status_note") val statusNote: String? = null,
    val highlight: String? = null,
    val capacity: Int? = null,
    @SerialName("capacity_left") val capacityLeft: Int? = null,
    val venue: NamedRef? = null,
    val place: String? = null,
    val lineup: List<DateLineupArtist> = emptyList(),
    @SerialName("practical_details") val practicalDetails: JsonObject = JsonObject(emptyMap()),
    @SerialName("cost_breakdown") val costBreakdown: JsonObject = JsonObject(emptyMap()),
    val currency: String = "EUR",
    val series: Boolean = false,
    @SerialName("is_exception") val isException: Boolean = false,
    val stats: DateStats? = null,
    @SerialName("ticketing_date_id") val ticketingDateId: Long? = null,
    @SerialName("poster_pdf") val posterPdf: Boolean = false,
    @SerialName("poster_pdf_url") val posterPdfUrl: String? = null,
    /* «Grafica social» (W-DT-12, P10): l'anteprima JPEG della grafica; c'è nell'elenco delle date dell'organizzazione. */
    @SerialName("social_preview") val socialPreview: Boolean = false,
    val abilities: DateAbilities = DateAbilities(),
) {
    val cancelled: Boolean get() = status.value == "cancelled"
    val soldOut: Boolean get() = status.value == "sold_out"
}

@Serializable
internal data class DatesEventInfo(
    val id: Long = 0,
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val city: String = "",
    val timezone: String = "Europe/Rome",
    val currency: String = "EUR",
)

/** La ripetizione già impostata, come la riapre «Ripeti» del pannello. */
@Serializable
internal data class EventRecurrenceState(
    val frequency: String = "weekly",
    val weekdays: List<String> = emptyList(),
    val until: String? = null,
    val description: String = "",
)

@Serializable
internal data class DatesListAbilities(
    val create: Boolean = false,
    val recurrence: Boolean = false,
    @SerialName("recurrence_needs_dates") val recurrenceNeedsDates: Boolean = false,
)

@Serializable
internal data class DatesPageMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    val total: Int = 0,
    val event: DatesEventInfo = DatesEventInfo(),
    val recurrence: EventRecurrenceState? = null,
    val abilities: DatesListAbilities = DatesListAbilities(),
)

@Serializable
internal data class DatesPage(
    val data: List<ManagedEventOccurrence> = emptyList(),
    val meta: DatesPageMeta = DatesPageMeta(),
)

@Serializable
internal data class DateSaveMeta(
    @SerialName("sent_to_review") val sentToReview: Boolean = false,
    val changed: Int = 0,
)

/** La risposta di una scrittura su una data: la data com'è adesso e che cosa è successo. */
@Serializable
internal data class DateSaveResponse(
    val data: ManagedEventOccurrence = ManagedEventOccurrence(),
    val meta: DateSaveMeta = DateSaveMeta(),
)

@Serializable
internal data class RecurrenceSaveMeta(val created: Int = 0)

@Serializable
internal data class RecurrenceSaveResponse(
    val data: EventRecurrenceState? = null,
    val meta: RecurrenceSaveMeta = RecurrenceSaveMeta(),
)

@Serializable
internal data class DateDetailResponse(
    val data: ManagedEventOccurrence = ManagedEventOccurrence(),
    val meta: DatesPageMeta = DatesPageMeta(),
)
