package it.fabiodalez.incitta.data

import kotlinx.serialization.json.*
import coil3.network.httpHeaders
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody

/** Community requests share the ordinary bearer session; no provider keys enter the app. */
internal class CommunityApi(private val api: ApiClient, private val token: String?) {
    val json get() = api.json
    fun avatar(context: android.content.Context, url: String): coil3.request.ImageRequest {
        val headers = coil3.network.NetworkHeaders.Builder()
        if (token != null && url.startsWith(it.fabiodalez.incitta.BuildConfig.API_BASE_URL + "community/avatar/")) headers.set("Authorization", "Bearer $token")
        return coil3.request.ImageRequest.Builder(context).data(url).httpHeaders(headers.build())
            .memoryCachePolicy(coil3.request.CachePolicy.DISABLED).diskCachePolicy(coil3.request.CachePolicy.DISABLED).build()
    }
    suspend fun profile(body: JsonObject, avatar: android.net.Uri?, context: android.content.Context): JsonObject {
        if (avatar == null) return change("profile", body)
        return withContext(Dispatchers.IO) {
            val photoError = context.getString(it.fabiodalez.incitta.R.string.community_photo_error)
            // An unreadable or oversized photo is the user's choice to fix, not a network failure.
            val bytes = try { context.contentResolver.openInputStream(avatar)?.use { it.readAvatarBytes() } }
                catch (_: java.io.IOException) { null } catch (_: SecurityException) { null }
                ?: throw CommunityInputException(photoError)
            if (bytes.size > 2 * 1024 * 1024) throw CommunityInputException(photoError)
            val multipart = okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM)
            body.forEach { (key, value) ->
                if (value is JsonArray) {
                    if (value.isEmpty()) multipart.addFormDataPart("$key[]", "")
                    else value.forEach { multipart.addFormDataPart("$key[]", (it as JsonPrimitive).content) }
                } else if (value == JsonNull) {
                    multipart.addFormDataPart(key, "")
                } else {
                    val primitive = value as JsonPrimitive
                    multipart.addFormDataPart(key, primitive.booleanOrNull?.let { if (it) "1" else "0" } ?: primitive.content)
                }
            }
            val mime = context.contentResolver.getType(avatar) ?: "application/octet-stream"
            multipart.addFormDataPart("avatar", "profile." + when (mime) { "image/png" -> "png"; "image/webp" -> "webp"; else -> "jpg" }, bytes.toRequestBody(mime.toMediaType()))
            val request = okhttp3.Request.Builder().url(it.fabiodalez.incitta.BuildConfig.API_BASE_URL + "community/profile")
                .header("Accept", "application/json").header("Authorization", "Bearer ${requireNotNull(token)}")
                .header("X-Installation-ID", api.installationId).post(multipart.build()).build()
            val response = try { api.client.newCall(request).execute() }
                catch (error: java.io.IOException) { throw java.io.IOException("Connessione non disponibile. Riprova tra poco.", error) }
            response.use {
                val payload = it.body.string()
                if (!it.isSuccessful) throw ApiException(it.code, runCatching { json.decodeFromString<ApiErrorEnvelope>(payload).error }.getOrNull())
                try { json.decodeFromString<JsonObject>(payload) }
                catch (error: kotlinx.serialization.SerializationException) { throw ApiPayloadException(error) }
            }
        }
    }
    suspend fun get(path: String): JsonObject = api.get("community/$path", token)
    suspend fun change(path: String, body: JsonObject = buildJsonObject {}, method: String = "POST"): JsonObject =
        api.execute("community/$path", method, body.toString(), requireNotNull(token))
    suspend fun notifications(cursor: String? = null): JsonObject = api.get("me/notifications?limit=30" + (cursor?.let { "&cursor=" + java.net.URLEncoder.encode(it, "UTF-8") } ?: ""), requireNotNull(token))
    suspend fun readNotifications(): JsonObject = api.post("me/notifications/read-all", buildJsonObject {}, requireNotNull(token))
    suspend fun refreshUser(): User = api.get<ApiEnvelope<User>>("me", requireNotNull(token)).data
    suspend fun resendEmail(): JsonObject = api.post("auth/verification/resend", buildJsonObject {}, requireNotNull(token))
}

/** Read at most the upload limit plus one sentinel byte, including on Android 8. */
internal fun java.io.InputStream.readAvatarBytes(): ByteArray {
    val bytes = ByteArray(2 * 1024 * 1024 + 1)
    var offset = 0
    while (offset < bytes.size) {
        val count = read(bytes, offset, bytes.size - offset)
        if (count < 0) break
        if (count == 0) {
            val next = read()
            if (next < 0) break
            bytes[offset++] = next.toByte()
        } else offset += count
    }
    return bytes.copyOf(offset)
}

/** A post whose event is missing or malformed still renders, just without the event row. */
internal fun JsonObject.occurrenceOrNull(json: Json): Occurrence? =
    (get("occurrence") as? JsonObject)?.let { runCatching { json.decodeFromJsonElement<Occurrence>(it) }.getOrNull() }

/** A problem the user fixes in the form (e.g. the photo): its message is shown as is. */
internal class CommunityInputException(message: String) : Exception(message)

internal fun communityFailureMessage(error: Throwable): String =
    if (error is CommunityInputException) error.message.orEmpty() else requestFailureMessage(error)

/** The server answers `delivery: uncertain` when the provider may not have delivered the code; anything else means sent. */
internal fun whatsappDeliveryUncertain(data: JsonObject): Boolean = data.text("delivery") == "uncertain"

internal fun JsonObject.text(key: String, fallback: String = ""): String = (get(key) as? JsonPrimitive)?.contentOrNull ?: fallback
internal fun JsonObject.flag(key: String): Boolean = (get(key) as? JsonPrimitive)?.booleanOrNull ?: false
internal fun JsonObject.number(key: String): Long = (get(key) as? JsonPrimitive)?.longOrNull ?: 0L
internal fun JsonObject.obj(key: String): JsonObject = get(key) as? JsonObject ?: buildJsonObject {}
internal fun JsonObject.rows(key: String): List<JsonObject> = (get(key) as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()
