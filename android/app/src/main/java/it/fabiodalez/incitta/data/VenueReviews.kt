package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class VenueReview(val id: Long, val author: String, val rating: Int, val body: String, val date: String? = null)
@Serializable
data class OwnVenueReview(val rating: Int, val body: String, val status: String, @SerialName("moderation_note") val moderationNote: String? = null)
@Serializable
data class VenueReviewPage(@SerialName("can_review") val canReview: Boolean = true, val count: Int = 0, val average: Double? = null, val reviews: List<VenueReview> = emptyList(), val page: Int = 1, @SerialName("last_page") val lastPage: Int = 1, @SerialName("my_review") val myReview: OwnVenueReview? = null)
@Serializable
data class VenueReviewBody(val rating: Int, val body: String)
