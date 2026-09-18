package it.fabiodalez.incitta.data

import kotlinx.serialization.json.*
import java.security.MessageDigest
import java.util.UUID

/** Retry identity contains only a fingerprint, never chat text or credentials. */
@kotlinx.serialization.Serializable
internal data class CarpoolPending(val fingerprint: String, val key: String, val session: String) {
    fun matches(path: String, body: JsonObject, token: String): Boolean =
        fingerprint == digest(path + "\n" + body.toString()) && session == digest(token)
    companion object {
        fun create(path: String, body: JsonObject, token: String) = CarpoolPending(digest(path + "\n" + body.toString()), UUID.randomUUID().toString(), digest(token))
        private fun digest(value: String): String = MessageDigest.getInstance("SHA-256").digest(value.toByteArray()).joinToString("") { "%02x".format(it) }
    }
}

internal class CarpoolApi(private val api: ApiClient, private val token: String, private val store: LocalStore) {
    suspend fun get(path: String): JsonObject = api.get("carpool/$path", token)
    suspend fun change(path: String, body: JsonObject = buildJsonObject {}): JsonObject {
        val pending = store.readCarpoolPendings().firstOrNull { it.matches(path, body, token) } ?: CarpoolPending.create(path, body, token).also(store::writeCarpoolPending)
        val request = buildJsonObject { body.forEach { (k, v) -> put(k, v) }; put("request_key", pending.key) }
        val response: JsonObject = api.execute("carpool/$path", "POST", request.toString(), token)
        store.removeCarpoolPending(pending.key)
        return response
    }
    suspend fun preferences(chat: Long, body: JsonObject): JsonObject = api.execute("carpool/chats/$chat/preferences", "POST", body.toString(), token)
    suspend fun notifications(cursor: String? = null): JsonObject = api.get("me/notifications?limit=30" + (cursor?.let { "&cursor=" + java.net.URLEncoder.encode(it, "UTF-8") } ?: ""), token)
    suspend fun readNotification(id: String): JsonObject = api.execute("me/notifications/$id/read", "PATCH", "{}", token)
    suspend fun readNotifications(through: Long): JsonObject = api.post("me/notifications/read-all", buildJsonObject { put("through", through) }, token)
}
