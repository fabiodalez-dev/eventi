package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import java.io.File
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** Quali date dell'elenco. */
internal enum class DatesPeriod(val value: String) { All("all"), Upcoming("upcoming"), Past("past") }

/**
 * Le rotte delle date (P2), sotto l'evento del soggetto:
 * `S/events/{event}/dates…`. Annulla, esaurito, elimina, «Ripeti» ed
 * esaurito sulla prossima data esistono solo per il locale, come nel
 * pannello: le chiamate prendono il soggetto, il server ricontrolla.
 */
internal class ManagementDatesApi(private val api: ApiClient, private val token: String) {
    suspend fun list(subject: ManagementSubject, eventId: Long, period: DatesPeriod, page: Int): DatesPage =
        api.get("${subject.apiPath}/events/$eventId/dates?period=${period.value}&page=$page", token)

    /** Una data con i campi del modulo, per riaprirla in modifica, e il quadro dell'evento. */
    suspend fun get(subject: ManagementSubject, eventId: Long, dateId: Long): DateDetailResponse =
        api.get("${subject.apiPath}/events/$eventId/dates/$dateId", token)

    /** `body` lo prepara `DateForm`: le date partono con lo scarto della città. */
    suspend fun create(subject: ManagementSubject, eventId: Long, body: JsonObject): DateSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/dates", "POST", body.toString(), token)

    suspend fun update(subject: ManagementSubject, eventId: Long, dateId: Long, body: JsonObject): DateSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/dates/$dateId", "PATCH", body.toString(), token)

    /** `status`: `cancelled`, `sold_out`, `scheduled`; `series` vale solo per l'annullamento. */
    suspend fun changeStatus(subject: ManagementSubject, eventId: Long, dateId: Long, status: String, series: Boolean = false): DateSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/dates/$dateId/status", "POST", buildJsonObject {
            put("status", status)
            put("scope", if (series) "series" else "single")
        }.toString(), token)

    suspend fun delete(subject: ManagementSubject, eventId: Long, dateId: Long) =
        api.send("${subject.apiPath}/events/$eventId/dates/$dateId", "DELETE", token)

    /** «Ripeti»: `until` è la data `yyyy-MM-dd` nella città, compresa. */
    suspend fun repeat(subject: ManagementSubject, eventId: Long, frequency: String, weekdays: Collection<String>, until: String?): RecurrenceSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/recurrence", "PUT", buildJsonObject {
            put("frequency", frequency)
            put("weekdays", buildJsonArray { weekdays.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            put("until", until)
        }.toString(), token)

    /** «Tutto esaurito» (vero) o «Di nuovo disponibile» (falso) sulla prossima data dell'evento. */
    suspend fun soldOutNext(subject: ManagementSubject, eventId: Long, soldOut: Boolean): DateSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/next-date/sold-out", "PUT", buildJsonObject { put("sold_out", soldOut) }.toString(), token)

    /** La locandina A4 in PDF della data, nella cartella dei file della gestione. */
    suspend fun poster(subject: ManagementSubject, eventId: Long, dateId: Long, directory: File): DownloadedFile =
        api.download("${subject.apiPath}/events/$eventId/dates/$dateId/poster.pdf", token, File(directory, "locandina-$eventId-$dateId.pdf"))
}
