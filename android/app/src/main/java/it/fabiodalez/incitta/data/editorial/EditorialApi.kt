package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.ManagementOptions
import java.net.URLEncoder
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/** Le chiamate delle fondamenta della redazione: interruttore, banco, elenchi, sblocco. */
internal interface EditorialDeskSource {
    /** `features.editorial` di `GET /config`: falso anche quando il campo manca. */
    suspend fun enabled(): Boolean
    suspend fun desk(city: String?): EditorialDesk
    suspend fun options(): ManagementOptions
    /** `POST editorial/unlock`: 422 con la password sbagliata. */
    suspend fun unlock(password: String): EditorialUnlockState
}

@Serializable
internal data class UnlockBody(val password: String)

internal class EditorialApi(private val api: ApiClient, private val token: String) : EditorialDeskSource {
    /* `config` è pubblico: niente bearer. */
    override suspend fun enabled(): Boolean = editorialFeatureEnabled(api.get<ApiEnvelope<JsonElement>>("config").data)
    override suspend fun desk(city: String?): EditorialDesk = api.get<ApiEnvelope<EditorialDesk>>(editorialPath("desk", city), token).data
    override suspend fun options(): ManagementOptions = ManagementOptions.from(api.get<ApiEnvelope<JsonElement>>("editorial/options", token).data)
    override suspend fun unlock(password: String): EditorialUnlockState =
        api.post<ApiEnvelope<EditorialUnlockState>, UnlockBody>("editorial/unlock", UnlockBody(password), token).data
}

/**
 * Un percorso della redazione con la città di lavoro: `editorial/<path>?city=<slug>`.
 * `path` può avere già dei parametri; senza città il server usa la prima attiva.
 */
internal fun editorialPath(path: String, city: String?, params: List<Pair<String, String>> = emptyList()): String {
    val all = buildList {
        addAll(params)
        city?.takeIf { it.isNotBlank() }?.let { add("city" to it) }
    }
    val base = "editorial/" + path.removePrefix("/").removePrefix("editorial/")
    if (all.isEmpty()) return base
    val query = all.joinToString("&") { (key, value) -> encode(key) + "=" + encode(value) }
    return base + (if ('?' in base) "&" else "?") + query
}

private fun encode(value: String): String = URLEncoder.encode(value, "UTF-8").replace("+", "%20").replace("%5B", "[").replace("%5D", "]")
