package it.fabiodalez.incitta.ui.editorial.catalog

import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CatalogBounds
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.ui.management.common.RichText
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * La bozza di un modulo del catalogo, una per le cinque voci: ogni voce usa
 * i suoi campi. Sta tutta in stringhe e booleani perché si salva nel
 * `SavedStateHandle` (sopravvive alla rotazione) e perché i campi numerici
 * si scrivono a mano: è il server a convalidarli con le regole del pannello,
 * qui si segnalano solo i vuoti obbligatori e i numeri scritti male.
 *
 * I testi formattati (presentazione, risposte, presentazioni per città) si
 * scrivono con i segni dell'editor comune (`RichText`): in uscita diventano
 * l'HTML del sito. Il corpo delle pagine è Markdown e resta com'è.
 */

@Serializable
internal data class FaqDraftRow(val question: String = "", val answer: String = "")

@Serializable
internal data class CityIntroDraftRow(val cityId: String = "", val text: String = "")

@Serializable
internal data class FlagDraftRow(val key: String = "", val enabled: Boolean = true)

@Serializable
internal data class CatalogDraft(
    val name: String = "",
    val title: String = "",
    val slug: String = "",
    // Categorie
    val parentId: String = "",
    val icon: String = "",
    val color: String = "",
    val sortOrder: String = "0",
    val defaultDuration: String = "",
    val supportsOngoing: Boolean = true,
    val isNightlife: Boolean = false,
    val isActive: Boolean = true,
    // Tag
    val categoryId: String = "",
    val isApproved: Boolean = false,
    val synonyms: List<String> = emptyList(),
    // Caratteristiche
    val group: String = "Informazioni",
    val featureIcon: String = "check-circle",
    val description: String = "",
    val isSystem: Boolean = false,
    // Città
    val provinceCode: String = "",
    val provinceName: String = "",
    val region: String = "",
    val countryCode: String = "IT",
    val centerLat: String = "",
    val centerLng: String = "",
    val defaultZoom: String = "12",
    val radiusKm: String = "30",
    val boundsNorth: String = "",
    val boundsSouth: String = "",
    val boundsEast: String = "",
    val boundsWest: String = "",
    val timezone: String = "Europe/Rome",
    val nightCutoff: String = "06:00",
    val startingSoon: String = "180",
    val locale: String = "it",
    val settings: List<FlagDraftRow> = emptyList(),
    // Pagine
    val excerpt: String = "",
    val body: String = "",
    val isPublished: Boolean = false,
    val seoTitleColumn: String = "",
    val seoDescriptionColumn: String = "",
    val revision: String = "",
    // Informazioni utili e anteprima su Google
    val introduction: String = "",
    val faqs: List<FaqDraftRow> = emptyList(),
    val cityIntros: List<CityIntroDraftRow> = emptyList(),
    val seoTitle: String = "",
    val seoDescription: String = "",
    val seoImage: String = "",
    val seoIndexing: String = "automatic",
    val isDemo: Boolean = false,
) {
    /**
     * I problemi che si vedono già sul telefono, con il nome del campo del
     * server; il resto (indirizzo già usato, numeri fuori dai limiti) lo
     * dice il server.
     */
    fun problems(kind: CatalogKind): Map<String, Int> = buildMap {
        fun required(field: String, value: String) { if (value.isBlank()) put(field, R.string.editorial_catalog_required) }
        fun integer(field: String, value: String, optional: Boolean = false) {
            if (value.isBlank()) { if (!optional) put(field, R.string.editorial_catalog_required) } else if (value.trim().toIntOrNull() == null) put(field, R.string.editorial_catalog_integer)
        }
        fun decimal(field: String, value: String, optional: Boolean = false) {
            if (value.isBlank()) { if (!optional) put(field, R.string.editorial_catalog_required) } else if (value.trim().replace(',', '.').toDoubleOrNull() == null) put(field, R.string.editorial_catalog_number)
        }
        when (kind) {
            CatalogKind.Categories -> { required("name", name); integer("sort_order", sortOrder); integer("default_duration_minutes", defaultDuration, optional = true) }
            CatalogKind.Tags -> required("name", name)
            CatalogKind.Features -> { required("name", name); required("group", group); required("icon", featureIcon); integer("sort_order", sortOrder, optional = true) }
            CatalogKind.Cities -> {
                required("name", name); required("province_code", provinceCode); required("province_name", provinceName)
                required("region", region); required("country_code", countryCode)
                decimal("center_lat", centerLat); decimal("center_lng", centerLng)
                integer("default_zoom", defaultZoom); integer("radius_km", radiusKm); integer("starting_soon_minutes", startingSoon)
                decimal("bounds.north", boundsNorth, true); decimal("bounds.south", boundsSouth, true)
                decimal("bounds.east", boundsEast, true); decimal("bounds.west", boundsWest, true)
                if (!TIME.matches(nightCutoff.trim())) put("night_cutoff_time", R.string.editorial_catalog_time)
                settings.forEachIndexed { index, row -> if (row.key.isBlank()) put("settings.$index.key", R.string.editorial_catalog_required) }
            }
            CatalogKind.Pages -> { required("title", title); required("body", body); integer("sort_order", sortOrder, optional = true) }
        }
        if (kind.hasContent) {
            faqs.forEachIndexed { index, row ->
                if (row.question.isBlank()) put("content_details.faqs.$index.question", R.string.editorial_catalog_required)
                if (row.answer.isBlank()) put("content_details.faqs.$index.answer", R.string.editorial_catalog_required)
            }
            if (kind.taxonomy) cityIntros.forEachIndexed { index, row ->
                if (row.cityId.isBlank()) put("content_details.city_introductions.$index.city_id", R.string.editorial_catalog_required)
                if (row.text.isBlank()) put("content_details.city_introductions.$index.text", R.string.editorial_catalog_required)
            }
        }
    }

    /**
     * Il corpo di `POST`/`PATCH`: tutti i campi che il modulo della voce
     * mostra, come il modulo del pannello. `admin` aggiunge visibilità sui
     * motori e contenuto dimostrativo (il server li scarterebbe comunque).
     */
    fun toJson(kind: CatalogKind, admin: Boolean): JsonObject = buildJsonObject {
        when (kind) {
            CatalogKind.Categories -> {
                put("name", name.trim()); put("slug", nullable(slug))
                put("parent_id", parentId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull)
                put("icon", nullable(icon)); put("color", nullable(color))
                put("sort_order", sortOrder.trim().toIntOrNull() ?: 0)
                put("default_duration_minutes", defaultDuration.trim().toIntOrNull()?.let(::JsonPrimitive) ?: JsonNull)
                put("supports_ongoing", supportsOngoing); put("is_nightlife", isNightlife); put("is_active", isActive)
            }
            CatalogKind.Tags -> {
                put("name", name.trim()); put("slug", nullable(slug))
                put("category_id", categoryId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull)
                put("is_approved", isApproved)
                put("synonyms", buildJsonArray { synonyms.map { it.trim() }.filter { it.isNotEmpty() }.distinct().forEach { add(JsonPrimitive(it)) } })
            }
            CatalogKind.Features -> {
                put("name", name.trim()); put("group", group); put("icon", featureIcon)
                put("description", nullable(description)); put("sort_order", sortOrder.trim().toIntOrNull() ?: 0)
                if (!isSystem) put("is_active", isActive)
            }
            CatalogKind.Cities -> {
                put("name", name.trim()); put("slug", nullable(slug))
                put("province_code", provinceCode.trim()); put("province_name", provinceName.trim())
                put("region", region.trim()); put("country_code", countryCode.trim())
                put("center_lat", number(centerLat)); put("center_lng", number(centerLng))
                put("default_zoom", defaultZoom.trim().toIntOrNull() ?: 0); put("radius_km", radiusKm.trim().toIntOrNull() ?: 0)
                put("bounds", buildJsonObject {
                    put("north", number(boundsNorth)); put("south", number(boundsSouth))
                    put("east", number(boundsEast)); put("west", number(boundsWest))
                })
                put("timezone", timezone); put("night_cutoff_time", nightCutoff.trim())
                put("starting_soon_minutes", startingSoon.trim().toIntOrNull() ?: 0)
                put("locale", locale); put("is_active", isActive)
                put("settings", buildJsonArray {
                    settings.forEach { row -> add(buildJsonObject { put("key", row.key.trim()); put("enabled", row.enabled) }) }
                })
            }
            CatalogKind.Pages -> {
                put("title", title.trim()); put("slug", nullable(slug)); put("excerpt", nullable(excerpt))
                put("body", body); put("is_published", isPublished); put("sort_order", sortOrder.trim().toIntOrNull() ?: 0)
                put("seo_title", nullable(seoTitleColumn)); put("seo_description", nullable(seoDescriptionColumn))
                if (revision.isNotBlank()) put("revision", revision)
            }
        }
        if (kind.hasContent) {
            put("content_details", buildJsonObject {
                put("introduction", RichText.toHtml(introduction).ifBlank { null }?.let(::JsonPrimitive) ?: JsonNull)
                put("faqs", buildJsonArray {
                    faqs.forEach { row -> add(buildJsonObject { put("question", row.question.trim()); put("answer", RichText.toHtml(row.answer)) }) }
                })
                if (kind.taxonomy) put("city_introductions", buildJsonArray {
                    cityIntros.forEach { row -> add(buildJsonObject { put("city_id", row.cityId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull); put("text", RichText.toHtml(row.text)) }) }
                })
            })
            put("seo", buildJsonObject {
                put("title", nullable(seoTitle)); put("description", nullable(seoDescription)); put("image", nullable(seoImage))
                if (admin) put("indexing", seoIndexing)
            })
            if (admin) put("is_demo", isDemo)
        }
    }

    companion object {
        private val TIME = Regex("^([01]\\d|2[0-3]):[0-5]\\d$")

        private fun nullable(value: String) = value.trim().ifEmpty { null }?.let(::JsonPrimitive) ?: JsonNull
        private fun number(value: String) = value.trim().replace(',', '.').toDoubleOrNull()?.let(::JsonPrimitive) ?: JsonNull
        private fun decimal(value: Double?) = value?.let { if (it == Math.floor(it) && !it.isInfinite()) it.toLong().toString() else it.toString() } ?: ""

        /** La bozza di una voce nuova: i valori predefiniti del modulo del pannello. */
        fun empty(kind: CatalogKind): CatalogDraft = when (kind) {
            CatalogKind.Cities -> CatalogDraft(isActive = false)
            CatalogKind.Features -> CatalogDraft(sortOrder = "0")
            else -> CatalogDraft()
        }

        /** La bozza dal modulo salvato sul server. */
        fun from(kind: CatalogKind, record: CatalogRecord): CatalogDraft {
            val details = record.contentDetails
            val seo = record.seo
            val bounds: CatalogBounds = record.bounds
            return CatalogDraft(
                name = record.name,
                title = record.title,
                slug = record.slug,
                parentId = record.parentId?.toString().orEmpty(),
                icon = record.icon.orEmpty(),
                color = record.color.orEmpty(),
                sortOrder = record.sortOrder.toString(),
                defaultDuration = record.defaultDurationMinutes?.toString().orEmpty(),
                supportsOngoing = record.supportsOngoing,
                isNightlife = record.isNightlife,
                isActive = record.isActive,
                categoryId = record.categoryId?.toString().orEmpty(),
                isApproved = record.isApproved,
                synonyms = record.synonyms,
                group = record.group.ifBlank { "Informazioni" },
                featureIcon = if (kind == CatalogKind.Features) record.icon.orEmpty().ifBlank { "check-circle" } else "check-circle",
                description = record.description.orEmpty(),
                isSystem = record.isSystem,
                provinceCode = record.provinceCode,
                provinceName = record.provinceName,
                region = record.region,
                countryCode = record.countryCode,
                centerLat = decimal(record.centerLat),
                centerLng = decimal(record.centerLng),
                defaultZoom = record.defaultZoom.toString(),
                radiusKm = record.radiusKm.toString(),
                boundsNorth = decimal(bounds.north),
                boundsSouth = decimal(bounds.south),
                boundsEast = decimal(bounds.east),
                boundsWest = decimal(bounds.west),
                timezone = record.timezone.ifBlank { "Europe/Rome" },
                nightCutoff = record.nightCutoffTime.ifBlank { "06:00" },
                startingSoon = record.startingSoonMinutes.toString(),
                locale = record.locale,
                settings = record.settings.map { FlagDraftRow(it.key, it.enabled) },
                excerpt = record.excerpt,
                body = record.body,
                isPublished = record.isPublished,
                seoTitleColumn = record.seoTitle,
                seoDescriptionColumn = record.seoDescription,
                revision = record.revision.orEmpty(),
                introduction = RichText.toMarkup(details.introduction),
                faqs = details.faqs.map { FaqDraftRow(it.question, RichText.toMarkup(it.answer)) },
                cityIntros = details.cityIntroductions.map { CityIntroDraftRow(it.cityId?.toString().orEmpty(), RichText.toMarkup(it.text)) },
                seoTitle = seo.title,
                seoDescription = seo.description,
                seoImage = seo.image,
                seoIndexing = seo.indexing.ifBlank { "automatic" },
                isDemo = record.isDemo,
            )
        }

        /** I campi del modulo con i nomi del server: gli errori 422 vanno sotto di loro. */
        fun fieldKeys(kind: CatalogKind): List<String> {
            val own = when (kind) {
                CatalogKind.Categories -> listOf("name", "slug", "parent_id", "icon", "color", "sort_order", "default_duration_minutes", "supports_ongoing", "is_nightlife", "is_active")
                CatalogKind.Tags -> listOf("name", "slug", "category_id", "is_approved", "synonyms")
                CatalogKind.Features -> listOf("name", "group", "icon", "description", "sort_order", "is_active")
                CatalogKind.Cities -> listOf(
                    "name", "slug", "province_code", "province_name", "region", "country_code", "center_lat", "center_lng",
                    "default_zoom", "radius_km", "bounds.north", "bounds.south", "bounds.east", "bounds.west", "timezone",
                    "night_cutoff_time", "starting_soon_minutes", "locale", "is_active", "settings",
                )
                CatalogKind.Pages -> listOf("title", "slug", "excerpt", "body", "is_published", "sort_order", "seo_title", "seo_description")
            }
            val content = if (kind.hasContent) listOf(
                "content_details.introduction", "content_details.faqs", "content_details.city_introductions",
                "seo.title", "seo.description", "seo.image", "seo.indexing", "is_demo",
            ) else emptyList()
            return own + content
        }
    }
}
