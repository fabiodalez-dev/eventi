package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class EventCommentPage(
    val comments: List<EventComment> = emptyList(),
    val page: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    val total: Int = 0,
    val thread: Long? = null,
    @SerialName("replies_page") val repliesPage: Int = 1,
    @SerialName("replies_last_page") val repliesLastPage: Int = 1,
    @SerialName("can_comment") val canComment: Boolean = false,
)

@Serializable
data class EventComment(
    val id: Long,
    val author: String,
    val body: String? = null,
    val hidden: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("reactions_count") val reactionsCount: Int = 0,
    @SerialName("my_reaction") val myReaction: String? = null,
    @SerialName("can_delete") val canDelete: Boolean = false,
    @SerialName("replies_count") val repliesCount: Int = 0,
    val replies: List<EventComment> = emptyList(),
)

@Serializable
data class CommentBody(val body: String, @SerialName("parent_id") val parentId: Long? = null)
@Serializable
data class CommentReactionBody(val type: String)

@Serializable
data class CommentPosted(val id: Long)
