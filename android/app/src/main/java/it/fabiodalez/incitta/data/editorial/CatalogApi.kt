package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/*
 * R11 — il catalogo della redazione (R-CT-01…07): categorie, tag,
 * caratteristiche degli eventi, città e pagine. Le cinque voci hanno le
 * stesse rotte (`E/<voce>`, `E/<voce>/{id}`); chi può fare che cosa lo
 * dicono `sections` dell'ingresso e le `abilities` di ogni riga, calcolate
 * dal server con le policy del pannello. Qui solo le forme.
 */

/** Le cinque voci, nell'ordine del menu del pannello: `key` è quella del server, `path` quella dell'indirizzo. */
internal enum class CatalogKind(val key: String, val path: String) {
    Categories("categories", "categories"),
    Tags("tags", "tags"),
    Features("event_features", "event-features"),
    Cities("cities", "cities"),
    Pages("pages", "pages");

    /** Le voci con le sezioni «Informazioni utili» e «Anteprima su Google». */
    val hasContent: Boolean get() = this != Features

    /** Categorie e tag hanno anche le presentazioni per città. */
    val taxonomy: Boolean get() = this == Categories || this == Tags

    companion object {
        fun of(key: String): CatalogKind? = entries.firstOrNull { it.key == key }
    }
}

@Serializable
internal data class CatalogSectionSummary(
    val key: String = "",
    val count: Int = 0,
    @SerialName("can_create") val canCreate: Boolean = false,
) {
    val kind: CatalogKind? get() = CatalogKind.of(key)
}

@Serializable
internal data class CatalogOverview(val sections: List<CatalogSectionSummary> = emptyList())

@Serializable
internal data class CatalogChoice(val value: String = "", val label: String = "") {
    fun toOption() = OptionChoice(value, label)
}

@Serializable
internal data class CatalogLimits(
    val text: Int = 5000,
    val faqs: Int = 30,
    @SerialName("seo_title") val seoTitle: Int = 180,
    @SerialName("seo_description") val seoDescription: Int = 400,
)

/** Le scelte dei moduli: `GET E/catalog/choices`. */
@Serializable
internal data class CatalogChoices(
    val categories: List<CatalogChoice> = emptyList(),
    val cities: List<CatalogChoice> = emptyList(),
    @SerialName("feature_groups") val featureGroups: List<CatalogChoice> = emptyList(),
    @SerialName("feature_icons") val featureIcons: List<CatalogChoice> = emptyList(),
    val timezones: List<String> = emptyList(),
    val locales: List<String> = listOf("it", "en", "de"),
    @SerialName("seo_indexing") val seoIndexing: List<CatalogChoice> = emptyList(),
    val limits: CatalogLimits = CatalogLimits(),
)

@Serializable
internal data class CatalogRef(val id: Long = 0, val name: String = "")

@Serializable
internal data class CatalogFaq(val question: String = "", val answer: String = "")

@Serializable
internal data class CatalogCityIntro(@SerialName("city_id") val cityId: Long? = null, val text: String = "")

@Serializable
internal data class CatalogContentDetails(
    val introduction: String = "",
    val faqs: List<CatalogFaq> = emptyList(),
    @SerialName("city_introductions") val cityIntroductions: List<CatalogCityIntro> = emptyList(),
)

@Serializable
internal data class CatalogSeo(
    val title: String = "",
    val description: String = "",
    val image: String = "",
    val indexing: String = "automatic",
)

@Serializable
internal data class CatalogBounds(val north: Double? = null, val south: Double? = null, val east: Double? = null, val west: Double? = null)

@Serializable
internal data class CatalogFlag(val key: String = "", val enabled: Boolean = false)

/**
 * Una voce del catalogo: la riga dell'elenco e, da `GET E/<voce>/{id}` o da
 * un salvataggio, anche il modulo. Le cinque voci condividono la forma:
 * ogni voce riempie i suoi campi, gli altri restano ai valori predefiniti.
 */
@Serializable
internal data class CatalogRecord(
    val id: Long = 0,
    val name: String = "",
    val title: String = "",
    val slug: String = "",
    // Categorie
    val parent: CatalogRef? = null,
    @SerialName("parent_id") val parentId: Long? = null,
    val icon: String? = null,
    val color: String? = null,
    @SerialName("sort_order") val sortOrder: Int = 0,
    @SerialName("default_duration_minutes") val defaultDurationMinutes: Int? = null,
    @SerialName("supports_ongoing") val supportsOngoing: Boolean = true,
    @SerialName("is_nightlife") val isNightlife: Boolean = false,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("events_count") val eventsCount: Int = 0,
    // Tag
    val category: CatalogRef? = null,
    @SerialName("category_id") val categoryId: Long? = null,
    @SerialName("usage_count") val usageCount: Int = 0,
    @SerialName("is_approved") val isApproved: Boolean = false,
    val synonyms: List<String> = emptyList(),
    // Caratteristiche
    val group: String = "",
    val description: String? = null,
    @SerialName("is_system") val isSystem: Boolean = false,
    // Città
    @SerialName("province_name") val provinceName: String = "",
    @SerialName("province_code") val provinceCode: String = "",
    val region: String = "",
    @SerialName("country_code") val countryCode: String = "IT",
    val timezone: String = "",
    @SerialName("night_cutoff_time") val nightCutoffTime: String = "",
    @SerialName("starting_soon_minutes") val startingSoonMinutes: Int = 0,
    @SerialName("venues_count") val venuesCount: Int = 0,
    @SerialName("center_lat") val centerLat: Double? = null,
    @SerialName("center_lng") val centerLng: Double? = null,
    @SerialName("default_zoom") val defaultZoom: Int = 12,
    @SerialName("radius_km") val radiusKm: Int = 30,
    val bounds: CatalogBounds = CatalogBounds(),
    val locale: String = "it",
    val settings: List<CatalogFlag> = emptyList(),
    // Pagine
    @SerialName("is_published") val isPublished: Boolean = false,
    @SerialName("updated_at") val updatedAt: String? = null,
    val excerpt: String = "",
    val body: String = "",
    @SerialName("seo_title") val seoTitle: String = "",
    @SerialName("seo_description") val seoDescription: String = "",
    val url: String? = null,
    val revision: String? = null,
    // Informazioni utili e anteprima su Google
    @SerialName("content_details") val contentDetails: CatalogContentDetails = CatalogContentDetails(),
    val seo: CatalogSeo = CatalogSeo(),
    @SerialName("is_demo") val isDemo: Boolean = false,
    /* La pagina della voce nel pannello: eliminare si fa lì (decisione 4), l'app ci porta con «Elimina sul sito». */
    @SerialName("web_path") val webPath: String = "",
    val abilities: JsonElement? = null,
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
    fun allows(ability: String): Boolean = can[ability] == true
    /** Il nome da mostrare: le pagine hanno un titolo, le altre voci un nome. */
    val label: String get() = name.ifBlank { title }
}

@Serializable
internal data class CatalogMessageMeta(val message: String? = null, val moved: Int? = null)

@Serializable
internal data class CatalogEnvelope(val data: CatalogRecord = CatalogRecord(), val meta: CatalogMessageMeta = CatalogMessageMeta())

/** Il risultato di un salvataggio o di un'unione: la voce com'è adesso e il messaggio del server. */
internal data class CatalogChange(val record: CatalogRecord, val message: String?)

@Serializable
internal data class CatalogPreview(val html: String = "")

@Serializable
internal data class CatalogMergeBody(@SerialName("absorbed_id") val absorbedId: Long)

@Serializable
internal data class CatalogPreviewBody(val body: String)

/** Le chiamate dell'area «Catalogo»; un'interfaccia per provare i ViewModel con un server finto. */
internal interface EditorialCatalogSource {
    suspend fun overview(): CatalogOverview
    suspend fun choices(): CatalogChoices
    suspend fun list(kind: CatalogKind, params: List<Pair<String, String>>): EditorialPage<CatalogRecord>
    suspend fun show(kind: CatalogKind, id: Long): CatalogRecord
    suspend fun create(kind: CatalogKind, body: JsonObject): CatalogChange
    suspend fun update(kind: CatalogKind, id: Long, body: JsonObject): CatalogChange
    suspend fun mergeTag(keepId: Long, absorbedId: Long): CatalogChange
    suspend fun previewPage(body: String): String
}

/**
 * `E/<voce>…` con il client della sessione. Il catalogo non dipende dalla
 * città di lavoro (il pannello mostra tutto), ma il parametro va comunque
 * sui percorsi, come per le altre aree. I corpi sono `JsonObject` scritti a
 * mano: il client omette i `null` delle classi, e qui un `null` vuol dire
 * «togli» (la categoria padre, un confine della mappa).
 */
internal class EditorialCatalogApi(
    private val api: ApiClient,
    private val token: String,
    private val city: () -> String? = { null },
) : EditorialCatalogSource {
    private fun path(path: String, params: List<Pair<String, String>> = emptyList()) = editorialPath(path, city(), params)

    override suspend fun overview(): CatalogOverview = api.get<ApiEnvelope<CatalogOverview>>(path("catalog"), token).data
    override suspend fun choices(): CatalogChoices = api.get<ApiEnvelope<CatalogChoices>>(path("catalog/choices"), token).data
    override suspend fun list(kind: CatalogKind, params: List<Pair<String, String>>): EditorialPage<CatalogRecord> = api.get(path(kind.path, params), token)
    override suspend fun show(kind: CatalogKind, id: Long): CatalogRecord = api.get<CatalogEnvelope>(path("${kind.path}/$id"), token).data
    override suspend fun create(kind: CatalogKind, body: JsonObject): CatalogChange =
        api.execute<CatalogEnvelope>(path(kind.path), "POST", body.toString(), token).change()
    override suspend fun update(kind: CatalogKind, id: Long, body: JsonObject): CatalogChange =
        api.execute<CatalogEnvelope>(path("${kind.path}/$id"), "PATCH", body.toString(), token).change()
    override suspend fun mergeTag(keepId: Long, absorbedId: Long): CatalogChange =
        api.post<CatalogEnvelope, CatalogMergeBody>(path("tags/$keepId/merge"), CatalogMergeBody(absorbedId), token).change()
    override suspend fun previewPage(body: String): String =
        api.post<ApiEnvelope<CatalogPreview>, CatalogPreviewBody>(path("pages/preview"), CatalogPreviewBody(body), token).data.html

    private fun CatalogEnvelope.change() = CatalogChange(data, meta.message)
}
