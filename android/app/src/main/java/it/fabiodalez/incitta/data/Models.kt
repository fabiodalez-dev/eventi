package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

@Serializable
data class ApiEnvelope<T>(
    val data: T,
    val meta: PageMeta? = null,
)

@Serializable
data class PageMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    @SerialName("next_cursor") val nextCursor: String? = null,
    @SerialName("has_more") val hasMore: Boolean = false,
)

@Serializable
data class Occurrence(
    @SerialName("booking_enabled") val bookingEnabled: Boolean = false,
    @SerialName("occurrence_id") val occurrenceId: Long,
    @SerialName("event_id") val eventId: Long,
    @SerialName("event_slug") val eventSlug: String,
    @SerialName("starts_at") val startsAt: String,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("effective_ends_at") val effectiveEndsAt: String? = null,
    @SerialName("ends_at_estimated") val endsAtEstimated: Boolean = false,
    @SerialName("doors_at") val doorsAt: String? = null,
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    val status: String = "scheduled",
    @SerialName("status_note") val statusNote: String? = null,
    val title: String,
    val subtitle: String? = null,
    @SerialName("short_description") val shortDescription: String? = null,
    val poster: Poster? = null,
    val venue: Venue? = null,
    @SerialName("custom_location") val customLocation: JsonElement? = null,
    val category: Category? = null,
    val tags: List<Tag>? = null,
    val price: Price? = null,
    val tiers: List<TicketTier> = emptyList(),
    @SerialName("is_outdoor") val isOutdoor: Boolean = false,
    val url: String? = null,
    @SerialName("is_saved") val isSaved: Boolean = false,
    @SerialName("content_details") val contentDetails: JsonElement? = null,
)

@Serializable
data class Poster(
    val thumb: String? = null,
    val card: String? = null,
    val full: String? = null,
    val width: Int? = null,
    val height: Int? = null,
)

@Serializable
data class Venue(
    @SerialName("content_details") val contentDetails: JsonElement? = null,
    val id: Long? = null,
    val slug: String? = null,
    val name: String,
    val municipality: String? = null,
    val zone: String? = null,
    val address: String? = null,
    @SerialName("address_extra") val addressExtra: String? = null,
    @SerialName("postal_code") val postalCode: String? = null,
    @SerialName("province_code") val provinceCode: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    @SerialName("is_verified") val isVerified: Boolean = false,
    val type: String? = null,
    val description: String? = null,
    @SerialName("short_description") val shortDescription: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val website: String? = null,
    @Serializable(with = EmptyStringMapSerializer::class) val socials: Map<String, String> = emptyMap(),
    @SerialName("opening_hours") val openingHours: JsonElement? = null,
    val transit: List<TransitLine> = emptyList(),
    val capacity: Int? = null,
    @Serializable(with = EmptyBooleanMapSerializer::class) val accessibility: Map<String, Boolean> = emptyMap(),
    val info: List<Fact> = emptyList(),
    @SerialName("requires_membership") val requiresMembership: Boolean = false,
    @SerialName("membership_notes") val membershipNotes: String? = null,
    @Serializable(with = CoverUrlSerializer::class) val cover: String? = null,
    @SerialName("upcoming_occurrences") val upcomingOccurrences: Int? = null,
)

@Serializable
data class CustomLocation(
    val name: String? = null,
    val address: String? = null,
    val municipality: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
)

@Serializable
data class Category(
    val id: Long? = null,
    val slug: String? = null,
    val name: String,
    val color: String? = null,
)

@Serializable
data class Tag(
    val id: Long? = null,
    val slug: String,
    val name: String,
    @SerialName("usage_count") val usageCount: Int = 0,
)

@Serializable
data class Price(
    val type: String? = null,
    val min: Double? = null,
    val max: Double? = null,
    val currency: String = "EUR",
    val notes: String? = null,
    @SerialName("ticket_url") val ticketUrl: String? = null,
)

@Serializable
data class TicketTier(
    val name: String,
    val price: Double? = null,
    val currency: String? = "EUR",
    val status: String = "available",
    val url: String? = null,
    val note: String? = null,
)

@Serializable
data class ExternalBooking(
    val required: Boolean = false,
    val url: String? = null,
    val phone: String? = null,
)

@Serializable
data class Organizer(val name: String? = null, val url: String? = null, val id: Long? = null, val slug: String? = null, @SerialName("host_fallback") val hostFallback: Boolean = false)

@Serializable
data class ExternalLink(val label: String, val url: String)

@Serializable
data class Fact(val label: String, val value: String)

@Serializable
data class TransitLine(val mode: String, val text: String)

@Serializable
data class EventDetail(
    @SerialName("content_details") val contentDetails: JsonElement? = null,
    val id: Long,
    val slug: String,
    val title: String,
    val subtitle: String? = null,
    @SerialName("short_description") val shortDescription: String? = null,
    val description: String? = null,
    val poster: Poster? = null,
    val venue: Venue? = null,
    @SerialName("custom_location") val customLocation: CustomLocation? = null,
    val category: Category? = null,
    val tags: List<Tag> = emptyList(),
    val price: Price? = null,
    val organizer: Organizer = Organizer(),
    val tiers: List<TicketTier> = emptyList(),
    val booking: ExternalBooking = ExternalBooking(),
    @SerialName("age_restriction") val ageRestriction: String? = null,
    val language: String? = null,
    @SerialName("is_outdoor") val isOutdoor: Boolean = false,
    @SerialName("external_links") val externalLinks: List<ExternalLink> = emptyList(),
    val facts: List<Fact> = emptyList(),
    @SerialName("verification_status") val verificationStatus: String? = null,
    val occurrences: List<Occurrence> = emptyList(),
    val url: String? = null,
)

@Serializable
data class AuthPayload(
    val token: String,
    @SerialName("token_type") val tokenType: String = "Bearer",
    @SerialName("expires_at") val expiresAt: String? = null,
    val user: User,
    val message: String? = null,
)

@Serializable
data class User(
    val id: Long,
    val name: String? = null,
    val email: String,
    @SerialName("email_verified") val emailVerified: Boolean = false,
)

@Serializable
data class ApiMessage(val message: String? = null)

@Serializable
data class MergeResult(
    val merged: Int = 0,
    val ignored: Int = 0,
    @SerialName("occurrence_ids") val occurrenceIds: List<Long> = emptyList(),
    val message: String? = null,
)

@Serializable
data class SearchResults(
    val organizers: List<Organizer> = emptyList(),
    val events: List<Occurrence> = emptyList(),
    val venues: List<Venue> = emptyList(),
    val tags: List<Tag> = emptyList(),
)

@Serializable
data class MapMarker(
    val id: Long,
    @SerialName("event_id") val eventId: Long,
    @SerialName("event_slug") val eventSlug: String? = null,
    val lat: Double,
    val lng: Double,
    val title: String,
    @SerialName("starts_at") val startsAt: String,
)

@Serializable
data class ApiProblem(
    val message: String? = null,
    val code: String? = null,
    val fields: Map<String, List<String>> = emptyMap(),
)

@Serializable
data class ApiErrorEnvelope(val error: ApiProblem)

class ApiException(
    val status: Int,
    val problem: ApiProblem?,
) : Exception(problem?.fields?.values?.firstOrNull()?.firstOrNull() ?: problem?.message ?: "Errore di rete ($status)")

data class Session(
    val token: String,
    val user: User,
    val expiresAt: String?,
)
