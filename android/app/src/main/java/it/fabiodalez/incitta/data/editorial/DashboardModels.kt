package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.management.truthy
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.doubleOrNull

/*
 * Il riepilogo della redazione (R1, R-DB-01…09): la risposta di
 * `GET /v1/editorial/dashboard`. Le sezioni arrivano nell'ordine del pannello
 * e solo quelle che il pannello mostra a chi chiede; etichette, numeri già
 * scritti («38%», «—», «1.250,00») e frasi sono quelli del sito, così il
 * telefono non ne tiene una seconda copia.
 */

/** Risposta di `GET /v1/editorial/dashboard`. */
@Serializable
internal data class EditorialDashboard(
    /* La città dei riquadri; `null` se non esiste ancora nessuna città (resta solo «Nuove iscrizioni»). */
    val city: EditorialCity? = null,
    val sections: List<DashboardSection> = emptyList(),
) {
    fun section(key: String): DashboardSection? = sections.firstOrNull { it.key == key }
}

/**
 * Una sezione: riquadri (`stats`), oppure le ultime iscrizioni (`rows`,
 * `links`, `counts`), oppure il grafico dell'andamento (`chart`).
 */
@Serializable
internal data class DashboardSection(
    val key: String = "",
    val title: String = "",
    val description: String? = null,
    val stats: List<DashboardStat> = emptyList(),
    val counts: DashboardCounts? = null,
    val links: List<DashboardLink> = emptyList(),
    val rows: List<DashboardRegistration> = emptyList(),
    val empty: DashboardEmpty? = null,
    val chart: DashboardChart? = null,
) {
    companion object {
        const val QUEUE = "queue"
        const val PUBLISHING = "publishing"
        const val QUALITY = "quality"
        const val REGISTRATIONS = "registrations"
        const val RETURNS = "returns"
        const val PROMOTION = "promotion"
        const val SPONSORSHIPS = "sponsorships"
        const val SPONSORSHIP_TREND = "sponsorship_trend"
    }
}

/** Un riquadro: il numero già scritto come nel pannello, la frase, il colore e dove porta. */
@Serializable
internal data class DashboardStat(
    val key: String = "",
    val label: String = "",
    val value: String = "",
    /* Il numero nudo; `null` quando il pannello scrive «—». */
    val count: JsonElement? = null,
    val description: String? = null,
    /* I colori di Filament: `warning`, `danger`, `success`, `gray`, oppure `null`. */
    val tone: String? = null,
    val target: DashboardTarget? = null,
) {
    /** Il numero, se c'è: serve a dire se il riquadro è «da guardare». */
    val number: Double? get() = (count as? JsonPrimitive)?.takeUnless { it is JsonNull }?.let { it.doubleOrNull ?: it.contentOrNull?.toDoubleOrNull() }

    /** Da guardare: il pannello lo colora d'arancio o di rosso. */
    val alert: Boolean get() = tone == TONE_WARNING || tone == TONE_DANGER

    companion object {
        const val TONE_WARNING = "warning"
        const val TONE_DANGER = "danger"
        const val TONE_SUCCESS = "success"
        const val TONE_GRAY = "gray"
    }
}

/**
 * Dove porta un riquadro: l'elenco di un'area con i parametri della
 * convenzione degli elenchi (`filter[status]=pending`, `tab=pending`) e la
 * stessa pagina del pannello (`web_path`), già filtrata.
 */
@Serializable
internal data class DashboardTarget(
    /* `events`, `venues`, `applications`, `reports`, `import_sources`, `users`. */
    val area: String = "",
    val tab: String? = null,
    /* Sempre un oggetto; tollerante se un server vecchio manda `[]`. */
    val filters: JsonElement? = null,
    @SerialName("web_path") val webPath: String = "",
) {
    /** I filtri come mappa: chiave del filtro del pannello → valore (`1` per i sì/no). */
    val filterMap: Map<String, String>
        get() = (filters as? JsonObject)?.mapNotNull { (key, value) ->
            (value as? JsonPrimitive)?.takeUnless { it is JsonNull }?.contentOrNull?.let { key to it }
        }?.toMap() ?: emptyMap()
}

@Serializable
internal data class DashboardCounts(val recent: Int = 0, val pending: Int = 0)

/** «Solo in attesa» (con il numero) e «Tutti gli utenti». */
@Serializable
internal data class DashboardLink(
    val key: String = "",
    val label: String = "",
    val badge: Int? = null,
    val target: DashboardTarget? = null,
)

/** Un'iscrizione recente: nome (o email), riga sotto, stato e quando. */
@Serializable
internal data class DashboardRegistration(
    val id: Long = 0,
    val name: String = "",
    val detail: String = "",
    val state: DashboardRegistrationState = DashboardRegistrationState(),
    @SerialName("registered_at") val registeredAt: String? = null,
    val abilities: JsonElement? = null,
    /* La modifica nel pannello, solo per chi può modificare la persona. */
    @SerialName("web_path") val webPath: String? = null,
) {
    val canUpdate: Boolean get() = truthy((abilities as? JsonObject)?.get("update"))
}

@Serializable
internal data class DashboardRegistrationState(
    val value: String = "",
    val label: String = "",
    val description: String = "",
    val tone: String? = null,
)

@Serializable
internal data class DashboardEmpty(val title: String = "", val description: String = "")

/** L'andamento degli ultimi trenta giorni: giorni `AAAA-MM-GG`, etichette brevi e due serie. */
@Serializable
internal data class DashboardChart(
    val days: List<String> = emptyList(),
    val labels: List<String> = emptyList(),
    val series: List<DashboardSeries> = emptyList(),
)

@Serializable
internal data class DashboardSeries(val key: String = "", val label: String = "", val values: List<Long> = emptyList())
