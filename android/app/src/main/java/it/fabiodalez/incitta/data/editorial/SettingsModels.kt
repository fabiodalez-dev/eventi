package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R13 — Configurazione tecnica: le risposte di `/api/v1/editorial/{notification-texts,
 * mail, newsletter, consent-scripts, cookies, redirects, cache}`. Ogni campo ha
 * un valore predefinito: un server più nuovo con un campo in più o in meno non
 * rompe la lettura. I segreti (password SMTP e di Redis) non arrivano mai:
 * al loro posto c'è `has_password`.
 */

/** La risposta di un'azione: il dato aggiornato e il messaggio del server. */
@Serializable
internal data class SettingsResult<T>(val data: T, val meta: SettingsMeta = SettingsMeta())

@Serializable
internal data class SettingsMeta(val message: String? = null)

// ------------------------------------------------------------ testi email

/** Un segnaposto dell'originale (`:title`) con il nome che il pannello mostra su «Inserisci». */
@Serializable
internal data class EmailTextVariable(val name: String = "", val label: String = "") {
    val token: String get() = ":$name"
}

@Serializable
internal data class EmailText(
    val key: String = "",
    /* «Oggetto», «Titolo dentro il messaggio»…: il pezzo dell'email, non la chiave. */
    val label: String = "",
    val default: String = "",
    val value: String = "",
    val modified: Boolean = false,
    val variables: List<EmailTextVariable> = emptyList(),
    /* L'esempio con i dati dimostrativi, reso dal server. */
    val preview: String = "",
    val rows: Int = 2,
    /* La revisione da rimandare salvando: se nel frattempo è cambiata, 422 su `revision`. */
    val revision: String = "",
)

@Serializable
internal data class EmailTextGroup(val key: String = "", val label: String = "", val texts: List<EmailText> = emptyList())

@Serializable
internal data class EmailTexts(
    val groups: List<EmailTextGroup> = emptyList(),
    /* Vero per il super admin, che vede anche i testi della newsletter. */
    @SerialName("newsletter_included") val newsletterIncluded: Boolean = false,
) {
    fun text(key: String): EmailText? = groups.firstNotNullOfOrNull { group -> group.texts.firstOrNull { it.key == key } }
    fun groupOf(key: String): EmailTextGroup? = groups.firstOrNull { group -> group.texts.any { it.key == key } }
}

@Serializable
internal data class TextPreview(val key: String = "", val preview: String = "", val unknown: List<String> = emptyList())

@Serializable
internal data class TextPreviewBody(val key: String, val value: String)

@Serializable
internal data class TextUpdateBody(val value: String, val revision: String)

/** Il campo del pannello: «Inserisci» aggiunge il segnaposto in fondo, dopo uno spazio. */
internal fun insertTextVariable(text: String, variable: EmailTextVariable): String = text.trimEnd() + " " + variable.token

/** I segnaposti di `text` (`:nome`), con la stessa regola del server. */
internal fun emailTextVariables(text: String): List<String> = Regex(":([a-z_]+)").findAll(text).map { it.groupValues[1] }.distinct().toList()

/** I segnaposti che l'originale non ha: il server li rifiuta salvando. */
internal fun unknownEmailVariables(text: String, default: String): List<String> = emailTextVariables(text) - emailTextVariables(default).toSet()

// ------------------------------------------------------------------ posta

@Serializable
internal data class MailStatus(val custom: Boolean = false, val title: String = "", val detail: String = "")

@Serializable
internal data class MailSettingsView(
    val enabled: Boolean = false,
    val active: Boolean = false,
    val host: String? = null,
    val port: Int? = null,
    val encryption: String? = null,
    @SerialName("encryption_label") val encryptionLabel: String = "",
    val username: String? = null,
    @SerialName("has_password") val hasPassword: Boolean = false,
    @SerialName("from_address") val fromAddress: String? = null,
    @SerialName("from_name") val fromName: String? = null,
    val verified: Boolean = false,
    @SerialName("verified_at") val verifiedAt: String? = null,
    /* Una prova c'è stata, ma per una configurazione diversa da quella salvata. */
    @SerialName("stale_check") val staleCheck: Boolean = false,
    val status: MailStatus = MailStatus(),
    val recipient: String? = null,
)

@Serializable
internal data class MailTestResult(val sent: Boolean = false, val title: String = "", val detail: String = "")

@Serializable
internal data class MailTestOutcome(val result: MailTestResult = MailTestResult(), val mail: MailSettingsView = MailSettingsView())

// ------------------------------------------------------------- newsletter

@Serializable
internal data class NewsletterText(
    val value: String = "",
    val default: String = "",
    val max: Int = 200,
    val variables: List<EmailTextVariable> = emptyList(),
)

@Serializable
internal data class NewsletterWeekday(val value: Int = 1, val label: String = "")

@Serializable
internal data class NewsletterLimits(
    @SerialName("max_items_min") val maxItemsMin: Int = 1,
    @SerialName("max_items_max") val maxItemsMax: Int = 30,
)

@Serializable
internal data class NewsletterSettingsView(
    val enabled: Boolean = false,
    val weekday: Int = 5,
    val time: String = "",
    @SerialName("max_items") val maxItems: Int = 5,
    /* `subject`, `heading`, `line`, `action`. */
    val texts: Map<String, NewsletterText> = emptyMap(),
    val weekdays: List<NewsletterWeekday> = emptyList(),
    val limits: NewsletterLimits = NewsletterLimits(),
)

@Serializable
internal data class NewsletterBody(
    val enabled: Boolean,
    val weekday: Int,
    val time: String,
    @SerialName("max_items") val maxItems: Int,
    val subject: String?,
    val heading: String?,
    val line: String?,
    val action: String?,
)

/** I campi dei testi della newsletter, nell'ordine del modulo del pannello. */
internal object NewsletterFields {
    const val SUBJECT = "subject"
    const val HEADING = "heading"
    const val LINE = "line"
    const val ACTION = "action"
    val texts = listOf(SUBJECT, HEADING, LINE, ACTION)
    val TIME = Regex("^([01]\\d|2[0-3]):[0-5]\\d$")
}

// ---------------------------------------------------------- consenso

@Serializable
internal data class ConsentCategoryInfo(val value: String = "", val label: String = "", val description: String = "")

@Serializable
internal data class ConsentScriptRow(
    val id: Long = 0,
    val name: String = "",
    val category: String = "",
    @SerialName("category_label") val categoryLabel: String = "",
    val enabled: Boolean = false,
    @SerialName("sort_order") val sortOrder: Int = 0,
    val src: String? = null,
    @SerialName("has_code") val hasCode: Boolean = false,
    /* Solo nel dettaglio. */
    val code: String? = null,
)

@Serializable
internal data class CookieRow(
    val id: Long = 0,
    val category: String = "",
    @SerialName("category_label") val categoryLabel: String = "",
    val name: String = "",
    /* Vuoto: lo deposita questo sito. */
    val provider: String? = null,
    val purpose: String = "",
    val duration: String? = null,
    @SerialName("sort_order") val sortOrder: Int = 0,
    val abilities: JsonElement? = null,
)

/** La `meta` dell'elenco dei cookie: quella degli elenchi più la scheda e le finalità. */
@Serializable
internal data class CookiePageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    @SerialName("per_page") val perPage: Int = 20,
    val total: Int = 0,
    val tabs: JsonElement? = null,
    val tab: String? = null,
    val categories: List<ConsentCategoryInfo> = emptyList(),
) {
    fun page(): PageMeta = PageMeta(currentPage, lastPage, perPage, total, tabs)
}

@Serializable
internal data class CookiePage(val data: List<CookieRow> = emptyList(), val meta: CookiePageMeta = CookiePageMeta())

@Serializable
internal data class CookieBody(
    val category: String,
    val name: String,
    val provider: String?,
    val purpose: String,
    val duration: String?,
)

@Serializable
internal data class CookieOrderBody(val category: String, val ids: List<Long>)

/** Le finalità del sito, nell'ordine dell'enum, se il server non le ha ancora mandate. */
internal object ConsentCategoryValue {
    const val NECESSARY = "necessary"
    const val STATISTICS = "statistics"
    const val MARKETING = "marketing"
    val all = listOf(NECESSARY, STATISTICS, MARKETING)
}

/** Sposta la riga `id` di un posto (`delta` = -1 su, +1 giù); `null` se non si muove. */
internal fun movedCookieOrder(ids: List<Long>, id: Long, delta: Int): List<Long>? {
    val from = ids.indexOf(id)
    val to = from + delta
    if (from < 0 || to !in ids.indices) return null
    return ids.toMutableList().apply { add(to, removeAt(from)) }
}

/** I tetti del modulo del pannello. */
internal object CookieLimits {
    const val NAME_MAX = 255
    const val PROVIDER_MAX = 255
    const val PURPOSE_MAX = 255
    const val DURATION_MAX = 64
}

// ------------------------------------------------------------- redirect

@Serializable
internal data class RedirectCity(val id: Long = 0, val name: String = "")

@Serializable
internal data class RedirectRow(
    val id: Long = 0,
    @SerialName("from_path") val fromPath: String = "",
    @SerialName("to_path") val toPath: String = "",
    val city: RedirectCity? = null,
    val status: Int = 301,
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("is_wildcard") val isWildcard: Boolean = false,
    val hits: Int = 0,
    @SerialName("last_hit_at") val lastHitAt: String? = null,
)

@Serializable
internal data class RedirectStatusChoice(val value: Int = 301, val label: String = "")

@Serializable
internal data class RedirectOptions(
    val cities: List<RedirectCity> = emptyList(),
    val statuses: List<RedirectStatusChoice> = emptyList(),
    @SerialName("default_status") val defaultStatus: Int = 301,
)

@Serializable
internal data class RedirectBody(
    @SerialName("from_path") val fromPath: String,
    @SerialName("to_path") val toPath: String,
    @SerialName("city_id") val cityId: Long?,
    val status: Int,
    @SerialName("is_wildcard") val isWildcard: Boolean,
)

internal object RedirectLimits {
    const val PATH_MAX = 191
}

/**
 * La regola `InternalRedirectTarget` del sito: un percorso interno che
 * comincia con una sola barra, senza barre rovesciate né spazi. Il server
 * ricontrolla; qui serve a dirlo prima di mandare.
 */
internal fun isInternalRedirectTarget(value: String): Boolean {
    val decoded = runCatching { java.net.URLDecoder.decode(value.replace("+", "%2B"), "UTF-8") }.getOrDefault(value)
    return decoded.startsWith("/") && !decoded.startsWith("//") && '\\' !in decoded && decoded.none { it.code <= 0x20 || it.code == 0x7f }
}

// ------------------------------------------------------------------ cache

@Serializable
internal data class RedisView(
    val host: String = "",
    val port: Int = 6379,
    val database: Int = 0,
    val username: String? = null,
    val tls: Boolean = false,
    @SerialName("has_password") val hasPassword: Boolean = false,
)

@Serializable
internal data class CacheEnvironment(
    @SerialName("redis_extension") val redisExtension: Boolean = false,
    val litespeed: Boolean = false,
    val server: String = "",
)

@Serializable
internal data class CacheSettingsView(
    val managed: Boolean = false,
    val enabled: Boolean = false,
    val store: String = "",
    @SerialName("store_label") val storeLabel: String = "",
    @SerialName("ttl_minutes") val ttlMinutes: Int = 0,
    val redis: RedisView = RedisView(),
    val environment: CacheEnvironment = CacheEnvironment(),
)

@Serializable
internal data class CacheCleared(val cleared: Boolean = false)

@Serializable
internal data class CacheVerified(val ok: Boolean = false)
