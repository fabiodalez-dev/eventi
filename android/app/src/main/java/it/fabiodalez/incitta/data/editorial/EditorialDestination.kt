package it.fabiodalez.incitta.data.editorial

import java.net.URI
import java.net.URLDecoder

/**
 * Un indirizzo del pannello della redazione (`https://<sito>/admin/…`)
 * tradotto in una pagina che l'app sa aprire nell'area «Redazione».
 *
 * Arriva da dentro l'app (una notifica della campanella, la casella degli
 * avvisi, un collegamento interno di un'altra schermata) o da una notifica
 * dell'app con la prova dell'installazione (`NotificationLinkProof`): gli
 * App Links del manifest non includono `/admin`, e `MainActivity` non apre
 * nell'area un indirizzo che arriva da un'altra app senza la prova.
 *
 * Elenco chiuso: le pagine del pannello che hanno una schermata nativa,
 * con le forme che Filament usa (`/admin/{pagina}`, `/create`,
 * `/{record}`, `/{record}/edit`). Tutto il resto torna `null` e si apre nel
 * browser come prima. Lo slug o l'id servono solo a scegliere la
 * schermata: il permesso lo decide sempre il server.
 */
internal data class EditorialDestination(
    /** Il primo pezzo dopo `/admin` (`events`, `venue-applications`, `calendario`…); vuoto per la pagina iniziale. */
    val page: String = "",
    /** `create`, oppure `null`. */
    val action: String? = null,
    /** Lo slug o l'id del record, come lo scrive il pannello. */
    val record: String? = null,
    /** `/{record}/edit` invece di `/{record}`. */
    val edit: Boolean = false,
    /** `?event=` di `/admin/social`: lo studio ristretto a un evento. */
    val event: Long? = null,
    /** `/admin/statistiche-condivisioni/{tipo}/{id}`. */
    val subjectType: String? = null,
) {
    /** L'id numerico del record, per le pagine che lo usano al posto dello slug. */
    val recordId: Long? get() = record?.takeIf { ID.matches(it) }?.toLongOrNull()

    companion object {
        /** Le pagine del pannello con una schermata nell'app (nomi di `php artisan route:list --path=admin`). */
        val PAGES: Set<String> = setOf(
            "events", "event-submissions", "calendario",
            "venues", "venue-applications", "organizers",
            "users",
            "reports", "catalog-reviews", "event-comments",
            "community", "carpool",
            "sponsorships", "sponsorship-orders", "sponsorship-grants", "sponsorship-prices", "sponsorship-banners", "statistiche-sponsorizzazioni",
            "social", "social-settings",
            "statistiche-condivisioni", "seo-overview", "ricerche-senza-risultati",
            "categories", "tags", "event-features", "cities", "pages",
            "import-sources", "scheduled-notifications", "cron-e-programmazioni",
            "testi-delle-email", "configurazione-posta", "newsletter", "script-consenso", "cookie", "indirizzi-cambiati", "cache-performance",
        )

        /** Le pagine dove il record è uno slug (`Event` e `Venue` hanno `getRouteKeyName() = slug`). */
        val SLUG_PAGES: Set<String> = setOf("events", "venues")

        private val ID = Regex("^[1-9][0-9]{0,17}$")
        private val RECORD = Regex("^[A-Za-z0-9][A-Za-z0-9-]{0,190}$")
        private val ANALYTICS_TYPES = setOf("event", "venue", "organizer")

        fun parse(url: String, allowedOrigin: String): EditorialDestination? = runCatching {
            val uri = URI(url)
            val allowed = URI(allowedOrigin)
            if (uri.scheme != allowed.scheme || uri.host != allowed.host || uri.port != allowed.port || uri.userInfo != null) return null
            fromPath(uri.rawPath.orEmpty(), uri.rawQuery)
        }.getOrNull()

        /** `admin/events/…` (un percorso senza sito, come lo scrivono i `web_path` del server) o `/admin/…`. */
        fun fromPath(rawPath: String, rawQuery: String? = null): EditorialDestination? {
            val path = rawPath.substringBefore('?').trim().trimEnd('/').let { if (it.startsWith('/')) it else "/$it" }
            val query = rawQuery ?: rawPath.substringAfter('?', "").takeIf { it.isNotEmpty() }
            if (path == "/admin") return EditorialDestination()
            if (!path.startsWith("/admin/")) return null
            val parts = path.removePrefix("/admin/").split('/')
            if (parts.any { it.isEmpty() }) return null
            val page = parts[0]
            if (page !in PAGES) return null
            return when {
                parts.size == 1 -> EditorialDestination(page, event = if (page == "social") queryLong(query, "event") else null)
                parts.size == 2 && parts[1] == "create" -> EditorialDestination(page, action = "create")
                page == "statistiche-condivisioni" && parts.size == 3 && parts[1] in ANALYTICS_TYPES && ID.matches(parts[2]) ->
                    EditorialDestination(page, record = parts[2], subjectType = parts[1])
                parts.size == 2 && RECORD.matches(parts[1]) -> EditorialDestination(page, record = parts[1])
                parts.size == 3 && parts[2] == "edit" && RECORD.matches(parts[1]) -> EditorialDestination(page, record = parts[1], edit = true)
                else -> null
            }
        }

        private fun queryLong(query: String?, key: String): Long? = query.orEmpty().split('&').firstNotNullOfOrNull { pair ->
            val name = URLDecoder.decode(pair.substringBefore('='), "UTF-8")
            if (name != key) null else URLDecoder.decode(pair.substringAfter('=', ""), "UTF-8").takeIf { ID.matches(it) }?.toLongOrNull()
        }
    }
}
