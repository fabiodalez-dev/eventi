package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class VenueReview(val id: Long, val author: String, val rating: Int? = null, val body: String? = null, val date: String? = null)
@Serializable
data class OwnVenueReview(val rating: Int? = null, val body: String? = null, val status: String, @SerialName("moderation_note") val moderationNote: String? = null, val revision: Int = 0)
@Serializable
data class VenueReviewPage(@SerialName("can_review") val canReview: Boolean = false, val verified: Boolean = false, val count: Int = 0, val average: Double? = null, val reviews: List<VenueReview> = emptyList(), val page: Int = 1, @SerialName("last_page") val lastPage: Int = 1, @SerialName("my_review") val myReview: OwnVenueReview? = null)
@Serializable
data class VenueReviewBody(val rating: Int? = null, val body: String? = null, val revision: Int? = null)
