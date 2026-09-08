package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.BuildConfig
import java.io.IOException
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerializationException
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

internal class ApiClient(
    @PublishedApi internal val installationId: String,
    @PublishedApi internal val client: OkHttpClient = OkHttpClient(),
) {
    @PublishedApi internal val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        isLenient = true
    }

    suspend inline fun <reified T> get(path: String, token: String? = null): T =
        execute(path, "GET", null, token)

    suspend fun sponsorshipMetric(banner: SponsoredBanner, click: Boolean, page: String) = withContext(Dispatchers.IO) {
        if (!banner.validAt()) return@withContext
        val metric = if (click) "clicks" else "impressions"
        val request = Request.Builder()
            .url(BuildConfig.API_BASE_URL + "reports/sponsorships/${banner.id}/metrics/$metric")
            .header("Accept", "application/json")
            .header("X-Installation-ID", installationId)
            .header("X-Metric-Token", banner.metricToken)
            .post(json.encodeToString(buildMap {
                put("placement", "banner")
                put("page", page)
                if (click) put("click_id", java.util.UUID.randomUUID().toString())
            }).toRequestBody(JSON_MEDIA)).build()
        client.newCall(request).execute().use { /* A metric returns 204, not a JSON envelope. */ }
    }

    suspend inline fun <reified T, reified B> post(
        path: String,
        body: B,
        token: String? = null,
        idempotent: Boolean = false,
    ): T = execute(path, "POST", json.encodeToString(body), token, idempotent)

    suspend inline fun <reified T> delete(path: String, token: String): T =
        execute(path, "DELETE", "{}", token)

    suspend inline fun <reified T, reified B> delete(path: String, body: B, token: String): T =
        execute(path, "DELETE", json.encodeToString(body), token)

    suspend inline fun <reified T> execute(
        path: String,
        method: String,
        body: String?,
        token: String?,
        idempotent: Boolean = false,
    ): T = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url(BuildConfig.API_BASE_URL + path.removePrefix("/"))
            .header("Accept", "application/json")
            .header("Accept-Language", "it")
            .header("X-Installation-ID", installationId)
            .header("User-Agent", "inCitta-Android/${BuildConfig.VERSION_NAME}")
            .apply {
                if (token != null) header("Authorization", "Bearer $token")
                if (idempotent) header("Idempotency-Key", UUID.randomUUID().toString())
            }
            .method(
                method,
                when {
                    body != null -> body.toRequestBody(JSON_MEDIA)
                    method == "GET" -> null
                    else -> ByteArray(0).toRequestBody(JSON_MEDIA)
                },
            )
            .build()

        val response = try {
            client.newCall(request).execute()
        } catch (error: IOException) {
            throw IOException("Connessione non disponibile. Riprova tra poco.", error)
        }

        response.use {
            val payload = it.body.string()
            if (!it.isSuccessful) {
                val problem = try {
                    json.decodeFromString<ApiErrorEnvelope>(payload).error
                } catch (_: SerializationException) {
                    null
                }
                throw ApiException(it.code, problem)
            }
            try {
                json.decodeFromString<T>(payload)
            } catch (error: SerializationException) {
                throw ApiPayloadException(error)
            }
        }
    }

    companion object {
        @PublishedApi internal val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()
    }
}

@kotlinx.serialization.Serializable
data class LoginBody(val email: String, val password: String, @kotlinx.serialization.SerialName("device_name") val deviceName: String)

@kotlinx.serialization.Serializable
data class RegisterBody(
    val name: String? = null,
    val email: String,
    val password: String,
    @kotlinx.serialization.SerialName("password_confirmation") val passwordConfirmation: String,
    @kotlinx.serialization.SerialName("device_name") val deviceName: String,
    @kotlinx.serialization.SerialName("first_name") val firstName: String? = null,
    @kotlinx.serialization.SerialName("last_name") val lastName: String? = null,
)

@kotlinx.serialization.Serializable
data class SaveBody(@kotlinx.serialization.SerialName("occurrence_id") val occurrenceId: Long)

@kotlinx.serialization.Serializable
data class MergeBody(@kotlinx.serialization.SerialName("occurrence_ids") val occurrenceIds: List<Long>)

@kotlinx.serialization.Serializable
data class MagicLinkBody(val email: String)

@kotlinx.serialization.Serializable
data class MagicExchangeBody(val token: String, @kotlinx.serialization.SerialName("device_name") val deviceName: String)

@kotlinx.serialization.Serializable
data class DeleteAccountBody(val confirmation: String, val password: String)
