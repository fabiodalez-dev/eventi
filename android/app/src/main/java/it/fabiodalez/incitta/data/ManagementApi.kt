package it.fabiodalez.incitta.data

import kotlinx.serialization.Serializable
import kotlinx.serialization.SerialName
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

@Serializable
internal data class ManagedDate(
    val id: Long, val title: String = "", @SerialName("starts_at") val startsAt: String = "",
    val venue: String? = null, @SerialName("can_manage") val canManage: Boolean = false,
    @SerialName("can_check_in") val canCheckIn: Boolean = false,
    @SerialName("practical_details") val practical: JsonObject = buildJsonObject {},
    @SerialName("cost_breakdown") val costs: JsonObject = buildJsonObject {},
    val currency: String = "EUR", val staff: List<CheckinStaff> = emptyList(),
    val statistics: Map<String, Int> = emptyMap(), val availability: BookingAvailability? = null,
)
@Serializable internal data class CheckinStaff(val id: Long, val name: String, val email: String)
@Serializable internal data class ManagedCampaign(val id: Long, val title: String = "",
    @SerialName("starts_at") val startsAt: String = "", @SerialName("ends_at") val endsAt: String = "",
    val economics: CampaignEconomics? = null)
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

internal class ManagementApi(private val api: ApiClient, private val token: String) {
    suspend fun dates(past: Boolean, page: Int): ApiEnvelope<List<ManagedDate>> =
        api.get("management/dates?period=${if (past) "past" else "upcoming"}&page=$page", token)
    suspend fun date(id: Long): ManagedDate = api.get<ApiEnvelope<ManagedDate>>("management/dates/$id", token).data
    suspend fun staff(id: Long, email: String, remove: Boolean) {
        api.post<ApiEnvelope<ApiMessage>, JsonObject>("management/dates/$id/staff", buildJsonObject { put("email", email); put("remove", remove) }, token)
    }
    suspend fun details(id: Long, body: JsonObject) {
        api.execute<ApiEnvelope<ApiMessage>>("management/dates/$id/details", "PATCH", body.toString(), token)
    }
    suspend fun campaigns(page: Int): ApiEnvelope<List<ManagedCampaign>> = api.get("management/campaigns?page=$page", token)
    suspend fun campaign(id: Long): ManagedCampaign = api.get<ApiEnvelope<ManagedCampaign>>("management/campaigns/$id", token).data
    suspend fun message(id: Long, subject: String, body: String, includeWaitlisted: Boolean): AttendeeMessage =
        api.post<ApiEnvelope<AttendeeMessage>, AttendeeMessageBody>("ticketing/$id/message", AttendeeMessageBody(subject.trim(), body.trim(), includeWaitlisted), token, idempotent = true).data
    suspend fun guestBooking(id: Long, names: String, email: String?, phone: String?, note: String?): Booking =
        api.post<ApiEnvelope<Booking>, GuestBookingBody>("ticketing/$id/guest-booking", guestBookingBody(names, email, phone, note), token, idempotent = true).data
    suspend fun checkIn(read: PendingCheckin): CheckedInTicket = api.post<ApiEnvelope<CheckedInTicket>, JsonObject>(
        "ticketing/${read.occurrenceId}/check-in", buildJsonObject { put("code", read.code); put("request_key", read.requestKey) }, token).data
}

/** Il corpo della prenotazione dal banco: righe vuote tolte, campi vuoti non inviati. */
internal fun guestBookingBody(names: String, email: String?, phone: String?, note: String?): GuestBookingBody = GuestBookingBody(
    names = names.lines().map(String::trim).filter(String::isNotEmpty).joinToString("\n"),
    email = email?.trim()?.ifBlank { null },
    phone = phone?.filterNot(Char::isWhitespace)?.ifBlank { null },
    note = note?.trim()?.ifBlank { null },
)
