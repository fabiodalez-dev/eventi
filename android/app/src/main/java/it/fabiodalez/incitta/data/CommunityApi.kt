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
            val bytes = context.contentResolver.openInputStream(avatar)?.use { it.readNBytes(2 * 1024 * 1024 + 1) }
                ?: error(context.getString(it.fabiodalez.incitta.R.string.community_photo_error))
            require(bytes.size <= 2 * 1024 * 1024) { context.getString(it.fabiodalez.incitta.R.string.community_photo_error) }
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
            api.client.newCall(request).execute().use {
                val payload = it.body.string()
                if (!it.isSuccessful) throw ApiException(it.code, runCatching { json.decodeFromString<ApiErrorEnvelope>(payload).error }.getOrNull())
                json.decodeFromString<JsonObject>(payload)
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

internal fun JsonObject.text(key: String, fallback: String = ""): String = (get(key) as? JsonPrimitive)?.contentOrNull ?: fallback
internal fun JsonObject.flag(key: String): Boolean = (get(key) as? JsonPrimitive)?.booleanOrNull ?: false
internal fun JsonObject.number(key: String): Long = (get(key) as? JsonPrimitive)?.longOrNull ?: 0L
internal fun JsonObject.obj(key: String): JsonObject = get(key) as? JsonObject ?: buildJsonObject {}
internal fun JsonObject.rows(key: String): List<JsonObject> = (get(key) as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()
