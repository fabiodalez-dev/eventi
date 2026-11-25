package it.fabiodalez.incitta.data

import kotlinx.serialization.Serializable
import kotlinx.serialization.SerialName
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import it.fabiodalez.incitta.data.management.BookingSettings
import it.fabiodalez.incitta.data.management.BookingsPage
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventTicketing
import it.fabiodalez.incitta.data.management.ExportKind
import it.fabiodalez.incitta.data.management.ManagedBooking
import it.fabiodalez.incitta.data.management.MessageHistory

@Serializable
internal data class ManagedDate(
    val id: Long, val title: String = "", @SerialName("starts_at") val startsAt: String = "",
    val venue: String? = null, @SerialName("can_manage") val canManage: Boolean = false,
    @SerialName("can_check_in") val canCheckIn: Boolean = false,
    @SerialName("practical_details") val practical: JsonObject = buildJsonObject {},
    @SerialName("cost_breakdown") val costs: JsonObject = buildJsonObject {},
    val currency: String = "EUR", val staff: List<CheckinStaff> = emptyList(),
    val statistics: Map<String, Int> = emptyMap(), val availability: BookingAvailability? = null,
    /* Biglietteria completa (P3): campi aggiunti dal server, tutti facoltativi per i server di prima. */
    val poster: Poster? = null,
    val counts: DateCounts? = null,
    @SerialName("booking_enabled") val bookingEnabled: Boolean = false,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = true,
    val ended: Boolean = false,
    val timezone: String? = null,
    val address: String? = null,
    /** Le impostazioni delle prenotazioni salvate: solo nella scheda e solo per chi gestisce. */
    val settings: BookingSettings? = null,
)

/** I numeri di una data, quelli del riepilogo del sito. `capacity` vuoto: posti senza limite. */
@Serializable internal data class DateCounts(
    val confirmed: Int = 0,
    @SerialName("checked_in") val checkedIn: Int = 0,
    val waitlisted: Int = 0,
    val cancelled: Int = 0,
    @SerialName("no_show") val noShow: Int = 0,
    val capacity: Int? = null,
)
@Serializable internal data class CheckinStaff(val id: Long, val email: String, val name: String = "", val status: String = "accepted") {
    val pending: Boolean get() = status == "pending"
}
@Serializable internal data class ManagedCampaign(val id: Long, val title: String = "",
    @SerialName("starts_at") val startsAt: String = "", @SerialName("ends_at") val endsAt: String = "",
    val economics: CampaignEconomics? = null,
    /* L'avanzamento dell'unità che si è comprata (l'abilitazione, o la campagna
       singola): `goal` vuoto per il prodotto a periodo. Tutti e tre mancano con
       un server che non li manda ancora. */
    val goal: Long? = null, val delivered: Long? = null,
    @SerialName("pacing_state") val pacingState: String? = null,
) {
    /** Da 0 a 1 verso l'obiettivo; `null` senza obiettivo. Oltre l'obiettivo resta 1. */
    val progress: Float? get() = goal?.takeIf { it > 0 }?.let { ((delivered ?: 0L).toFloat() / it.toFloat()).coerceIn(0f, 1f) }
}
@Serializable internal data class CampaignEconomics(
    @SerialName("spend_cents") val spendCents: Long? = null, val currency: String = "EUR",
    val bookings: Int? = null, val attendances: Int? = null,
    @SerialName("per_booking_cents") val perBookingCents: Long? = null,
    @SerialName("per_attendance_cents") val perAttendanceCents: Long? = null,
)
@Serializable internal data class CheckedInTicket(val id: Long, @SerialName("attendee_name") val attendeeName: String, val status: String)

/** Risposta di `POST /v1/ticketing/{occurrence}/message`. */
@Serializable internal data class AttendeeMessage(val id: Long, @SerialName("recipients_count") val recipientsCount: Int = 0, @SerialName("created_at") val createdAt: String? = null)
@Serializable internal data class AttendeeMessageBody(val subject: String, val body: String, @SerialName("include_waitlisted") val includeWaitlisted: Boolean = false)
/** Prenotazione registrata dal banco: `names` è una stringa con un nome per riga, come nel modulo web. */
@Serializable internal data class GuestBookingBody(val names: String, val email: String? = null, val phone: String? = null, val note: String? = null)

/**
 * Le chiamate della biglietteria di una data. È un'interfaccia perché i
 * ViewModel della gestione si provano con un finto, senza rete.
 */
internal interface TicketingApi {
    /** Le date gestite o da controllare; `query` cerca nel titolo e nel nome del locale. */
    suspend fun dates(past: Boolean, page: Int, query: String): ApiEnvelope<List<ManagedDate>>
    suspend fun date(id: Long): ManagedDate
    suspend fun staff(id: Long, email: String, remove: Boolean)
    suspend fun details(id: Long, body: JsonObject)
    suspend fun campaigns(page: Int): ApiEnvelope<List<ManagedCampaign>>
    suspend fun campaign(id: Long): ManagedCampaign
    suspend fun message(id: Long, subject: String, body: String, includeWaitlisted: Boolean): AttendeeMessage
    suspend fun guestBooking(id: Long, names: String, email: String?, phone: String?, note: String?): Booking
    suspend fun checkIn(read: PendingCheckin): CheckedInTicket
    suspend fun manifest(id: Long): ManifestPayload

    /* Biglietteria completa (P3). */
    suspend fun bookings(id: Long, status: String?, query: String, page: Int): BookingsPage
    suspend fun cancelBooking(id: Long, bookingId: Long, ticketId: Long?, reason: String?): ManagedBooking
    suspend fun settings(id: Long, body: JsonObject): ManagedDate
    suspend fun messages(id: Long): MessageHistory
    suspend fun eventTicketing(venueId: Long, eventId: Long): EventTicketing
    /** Scarica il CSV dei partecipanti o la lista d'ingresso in PDF dentro `directory`. */
    suspend fun export(id: Long, kind: ExportKind, directory: java.io.File): DownloadedFile
}

internal class ManagementApi(private val api: ApiClient, private val token: String) : TicketingApi {
    override suspend fun dates(past: Boolean, page: Int, query: String): ApiEnvelope<List<ManagedDate>> =
        api.get("management/dates?period=${if (past) "past" else "upcoming"}&page=$page" + query.trim().takeIf { it.isNotEmpty() }?.let { "&q=" + queryParam(it) }.orEmpty(), token)
    override suspend fun date(id: Long): ManagedDate = api.get<ApiEnvelope<ManagedDate>>("management/dates/$id", token).data
    override suspend fun staff(id: Long, email: String, remove: Boolean) {
        api.post<ApiEnvelope<ApiMessage>, JsonObject>("management/dates/$id/staff", buildJsonObject { put("email", email); put("remove", remove) }, token)
    }
    override suspend fun details(id: Long, body: JsonObject) {
        api.execute<ApiEnvelope<ApiMessage>>("management/dates/$id/details", "PATCH", body.toString(), token)
    }
    override suspend fun campaigns(page: Int): ApiEnvelope<List<ManagedCampaign>> = api.get("management/campaigns?page=$page", token)
    override suspend fun campaign(id: Long): ManagedCampaign = api.get<ApiEnvelope<ManagedCampaign>>("management/campaigns/$id", token).data
    override suspend fun message(id: Long, subject: String, body: String, includeWaitlisted: Boolean): AttendeeMessage =
        api.post<ApiEnvelope<AttendeeMessage>, AttendeeMessageBody>("ticketing/$id/message", AttendeeMessageBody(subject.trim(), body.trim(), includeWaitlisted), token, idempotent = true).data
    override suspend fun guestBooking(id: Long, names: String, email: String?, phone: String?, note: String?): Booking =
        api.post<ApiEnvelope<Booking>, GuestBookingBody>("ticketing/$id/guest-booking", guestBookingBody(names, email, phone, note), token, idempotent = true).data
    override suspend fun checkIn(read: PendingCheckin): CheckedInTicket = api.post<ApiEnvelope<CheckedInTicket>, JsonObject>(
        "ticketing/${read.occurrenceId}/check-in", checkInBody(read), token).data
    override suspend fun manifest(id: Long): ManifestPayload = api.get<ApiEnvelope<ManifestPayload>>("ticketing/$id/manifest", token).data

    override suspend fun bookings(id: Long, status: String?, query: String, page: Int): BookingsPage =
        api.get("management/dates/$id/bookings?page=$page" + status?.let { "&status=" + queryParam(it) }.orEmpty() + query.trim().takeIf { it.isNotEmpty() }?.let { "&q=" + queryParam(it) }.orEmpty(), token)
    override suspend fun cancelBooking(id: Long, bookingId: Long, ticketId: Long?, reason: String?): ManagedBooking =
        api.post<ApiEnvelope<ManagedBooking>, JsonObject>("management/dates/$id/bookings/$bookingId/cancel", cancelBookingBody(ticketId, reason), token).data
    override suspend fun settings(id: Long, body: JsonObject): ManagedDate =
        api.execute<ApiEnvelope<ManagedDate>>("management/dates/$id/settings", "PATCH", body.toString(), token).data
    override suspend fun messages(id: Long): MessageHistory = api.get("management/dates/$id/messages", token)
    override suspend fun eventTicketing(venueId: Long, eventId: Long): EventTicketing =
        api.get<ApiEnvelope<EventTicketing>>("management/venues/$venueId/events/$eventId/ticketing", token).data
    override suspend fun export(id: Long, kind: ExportKind, directory: java.io.File): DownloadedFile =
        api.download("management/dates/$id/${kind.path}", token, java.io.File(directory, kind.fileName(id)))
}

/** Un valore in una query string. */
internal fun queryParam(value: String): String = java.net.URLEncoder.encode(value, Charsets.UTF_8.name())

/** Il corpo dell'annullamento da gestore: senza `ticket_id` si annulla tutta la prenotazione; il motivo vuoto non si manda. */
internal fun cancelBookingBody(ticketId: Long?, reason: String?): JsonObject = buildJsonObject {
    ticketId?.let { put("ticket_id", it) }
    reason?.trim()?.takeIf { it.isNotEmpty() }?.let { put("reason", it.take(500)) }
}

/** Il corpo della prenotazione dal banco: righe vuote tolte, campi vuoti non inviati. */
internal fun guestBookingBody(names: String, email: String?, phone: String?, note: String?): GuestBookingBody = GuestBookingBody(
    names = names.lines().map(String::trim).filter(String::isNotEmpty).joinToString("\n"),
    email = email?.trim()?.ifBlank { null },
    phone = phone?.filterNot(Char::isWhitespace)?.ifBlank { null },
    note = note?.trim()?.ifBlank { null },
)

internal fun checkInBody(read: PendingCheckin): JsonObject = buildJsonObject {
    put("code", read.code)
    put("request_key", read.requestKey)
    read.scannedAt?.let { put("scanned_at", it) }
}
