package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Una ricerca salvata («Avvisami per nuovi eventi come questi»). `filters`
 * ha le chiavi della query string di `/eventi` sul sito (`category`, `tag`,
 * `time`…), non quelle dell'API che l'app usa per cercare: la traduzione sta
 * in [SavedSearchFilters].
 */
@Serializable
data class SavedSearch(
    val id: Long,
    val name: String,
    @Serializable(with = EmptyStringMapSerializer::class) val filters: Map<String, String> = emptyMap(),
    val url: String? = null,
    @SerialName("last_checked_at") val lastCheckedAt: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable
internal data class SavedSearchBody(val filters: Map<String, String>, val name: String? = null)

internal class SavedSearchesApi(private val api: ApiClient, private val token: String) {
    suspend fun list(): List<SavedSearch> = api.get<ApiEnvelope<List<SavedSearch>>>("me/searches?city=$CITY", token).data

    suspend fun create(filters: Map<String, String>, name: String? = null): SavedSearch =
        api.post<ApiEnvelope<SavedSearch>, SavedSearchBody>("me/searches?city=$CITY", SavedSearchBody(filters, name?.trim()?.ifBlank { null }), token, idempotent = true).data

    suspend fun delete(id: Long) = api.send("me/searches/$id", "DELETE", null, token)

    private companion object { const val CITY = "padova" }
}

/**
 * Le chiavi dei filtri fra app e sito.
 *
 * L'app cerca con i nomi dell'API (`categories`, `tags`, `time_of_day`,
 * `preset`, `near`…); la ricerca salvata usa quelli della pagina `/eventi`.
 * Il server scarta comunque finestra temporale, ordinamento e posizione
 * (una ricerca salvata guarda sempre avanti), quindi qui non partono.
 */
internal object SavedSearchFilters {
    private val toSite = mapOf("categories" to "category", "tags" to "tag", "time_of_day" to "time")
    private val toApp = toSite.entries.associate { (app, site) -> site to app }
    private val kept = setOf(
        "category", "tag", "price", "time", "municipality", "zone", "venue", "accessible", "access",
        "outdoor", "family", "membership", "age", "stroller", "changing_table", "kids_area", "budget", "q",
    )

    /** I filtri dell'app più la parola cercata, nella forma che il server salva. */
    fun fromApp(filters: Map<String, String>, query: String): Map<String, String> {
        val site = filters.entries.associate { (key, value) -> (toSite[key] ?: key) to value.trim() } + ("q" to query.trim())
        return site.filter { (key, value) -> key in kept && value.isNotEmpty() }.toSortedMap()
    }

    /** I filtri di una ricerca salvata con i nomi dell'app, senza la parola cercata (che va a parte). */
    fun toApp(filters: Map<String, String>): Map<String, String> =
        filters.filterKeys { it != "q" }.entries.associate { (key, value) -> (toApp[key] ?: key) to value }

    /** Il pulsante «Avvisami» ha senso solo se resta almeno un criterio da salvare. */
    fun canSave(filters: Map<String, String>, query: String): Boolean = fromApp(filters, query).isNotEmpty()
}
