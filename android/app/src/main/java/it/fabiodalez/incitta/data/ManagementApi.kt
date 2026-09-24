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
    suspend fun checkIn(read: PendingCheckin): CheckedInTicket = api.post<ApiEnvelope<CheckedInTicket>, JsonObject>(
        "ticketing/${read.occurrenceId}/check-in", buildJsonObject { put("code", read.code); put("request_key", read.requestKey) }, token).data
}
