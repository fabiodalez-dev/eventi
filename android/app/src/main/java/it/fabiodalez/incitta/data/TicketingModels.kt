package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class BookingAvailability(
    val enabled: Boolean = false,
    val open: Boolean = false,
    val capacity: Int? = null,
    val remaining: Int? = null,
    @SerialName("limit_per_account") val limitPerAccount: Int = 6,
    val waitlist: Boolean = false,
    @SerialName("opens_at") val opensAt: String? = null,
    @SerialName("closes_at") val closesAt: String? = null,
    @SerialName("cancellation_closes_at") val cancellationClosesAt: String? = null,
    val instructions: String? = null,
    @SerialName("booker_fields") val bookerFields: List<BookingFormField> = emptyList(),
    @SerialName("privacy_url") val privacyUrl: String? = null,
)

@Serializable
data class Booking(
    val id: Long,
    @SerialName("occurrence_id") val occurrenceId: Long,
    val title: String,
    @SerialName("event_slug") val eventSlug: String? = null,
    val venue: String? = null,
    val address: String? = null,
    @SerialName("starts_at") val startsAt: String? = null,
    val status: String,
    val instructions: String? = null,
    @SerialName("cancellation_reason") val cancellationReason: String? = null,
    @SerialName("can_cancel") val canCancel: Boolean = false,
    val tickets: List<AdmissionTicket> = emptyList(),
)

@Serializable
data class AdmissionTicket(
    val id: Long,
    @SerialName("attendee_name") val attendeeName: String,
    val status: String,
    @SerialName("qr_payload") val qrPayload: String? = null,
    @SerialName("checked_in_at") val checkedInAt: String? = null,
)

@Serializable
data class ReserveBody(
    val attendees: List<AttendeeName>,
    @SerialName("request_key") val requestKey: String,
    val waitlist: Boolean,
    @SerialName("accept_terms") val acceptTerms: Boolean,
    val booker: Map<String, String>,
)

@Serializable
data class AttendeeName(@SerialName("first_name") val firstName: String = "", @SerialName("last_name") val lastName: String = "")

@Serializable
data class BookingFormField(val key: String, val label: String, val required: Boolean)

@Serializable
data class CancelBookingBody(@SerialName("ticket_id") val ticketId: Long? = null)
