package it.fabiodalez.incitta.data

import java.security.MessageDigest
import java.util.UUID
import kotlinx.serialization.Serializable

@Serializable
internal data class WhatsappPendingRequest(
    val id: String,
    val sessionDigest: String,
    val createdAt: Long,
    val challengeId: String? = null,
) {
    fun validFor(token: String, now: Long): Boolean =
        token.isNotBlank() && runCatching { UUID.fromString(id).toString() == id }.getOrDefault(false) &&
            sessionDigest == digest(token) && now >= createdAt && now - createdAt < MAX_AGE_MS

    fun accepts(requestId: String?, token: String, code: String, now: Long): Boolean =
        requestId == id && validFor(token, now) && code.matches(Regex("[0-9]{6}"))

    companion object {
        const val MAX_AGE_MS = 5 * 60 * 1000L
        fun digest(token: String): String = MessageDigest.getInstance("SHA-256")
            .digest(token.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
    }
}
