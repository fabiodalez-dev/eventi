package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull

/*
 * R12 — Sistema: le righe e i moduli del gruppo «Sistema» del pannello
 * (sorgenti di import, invii previsti, cron e programmazioni), come li manda
 * `/api/v1/editorial/{import-sources,scheduled-notifications,scheduled-operations}`.
 * Ogni campo ha un valore predefinito: un server più nuovo con un campo in
 * più o in meno non rompe la lettura. Le azioni permesse arrivano in
 * `abilities` (vedi `abilitiesOf`).
 */

/** Un riferimento con nome: città, locale, categoria. */
@Serializable
internal data class SystemRef(val id: Long = 0, val name: String = "")

/** Gli esiti di un'esecuzione (`ImportRunStatus`). */
internal object ImportRunStatusValue {
    const val SUCCESS = "success"
    const val PARTIAL = "partial"
    const val FAILED = "failed"
    val all = listOf(SUCCESS, PARTIAL, FAILED)
}

/** Gli stati di un invio previsto (`NotificationStatus`). */
internal object NotificationStatusValue {
    const val PENDING = "pending"
    const val SENT = "sent"
    const val SKIPPED = "skipped"
    const val FAILED = "failed"
    const val CANCELLED = "cancelled"
    val all = listOf(PENDING, SENT, SKIPPED, FAILED, CANCELLED)
}

/** Una sorgente di import: le colonne della tabella del pannello e i campi del modulo, mai le credenziali. */
@Serializable
internal data class EditorialImportSource(
    val id: Long = 0,
    val city: SystemRef? = null,
    val venue: SystemRef? = null,
    val type: String = "",
    @SerialName("type_label") val typeLabel: String = "",
    val url: String? = null,
    /* Solo se ci sono: si scrivono e si cambiano dal sito (decisione 6). */
    @SerialName("has_credentials") val hasCredentials: Boolean = false,
    /* Il dizionario della mappatura (`timezone`, `auth`, `exclude_keywords`…), così com'è. */
    val mapping: JsonElement? = null,
    @SerialName("default_category") val defaultCategory: SystemRef? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("last_run_at") val lastRunAt: String? = null,
    @SerialName("last_status") val lastStatus: String? = null,
    @SerialName("last_status_label") val lastStatusLabel: String? = null,
    @SerialName("last_error") val lastError: String? = null,
    /* Falso per i tipi senza lettore (`manual`…): niente anteprima né esecuzione, come nel pannello. */
    val runnable: Boolean = false,
    /* La scheda del pannello dove si scrivono le credenziali. */
    @SerialName("web_path") val webPath: String = "",
    val abilities: JsonElement? = null,
) {
    /** Ultima esecuzione andata male: la riga si colora come il distintivo rosso del pannello. */
    val failed: Boolean get() = lastStatus == ImportRunStatusValue.FAILED
    val label: String get() = venue?.name ?: url?.takeIf { it.isNotBlank() } ?: typeLabel.ifBlank { type }
}

/** Una riga dello storico delle esecuzioni (solo lettura). */
@Serializable
internal data class ImportRunRow(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("created_count") val createdCount: Int = 0,
    @SerialName("updated_count") val updatedCount: Int = 0,
    @SerialName("unchanged_count") val unchangedCount: Int = 0,
    @SerialName("excluded_count") val excludedCount: Int = 0,
    @SerialName("cancelled_count") val cancelledCount: Int = 0,
    @SerialName("error_count") val errorCount: Int = 0,
    val message: String? = null,
)

/** Una data dell'anteprima, già scritta nell'ora della città dal server (`05/09/2026 21:30`). */
@Serializable
internal data class ImportPreviewRow(
    @SerialName("when") val whenText: String = "",
    val title: String = "",
    val where: String? = null,
    val notes: List<String> = emptyList(),
    val cancelled: Boolean = false,
)

/** L'anteprima: le righe, oppure il motivo per cui il calendario non si legge. */
@Serializable
internal data class ImportPreview(
    val rows: List<ImportPreviewRow> = emptyList(),
    val error: String? = null,
    val timezone: String? = null,
)

@Serializable
internal data class ImportTypeChoice(val value: String = "", val label: String = "", val runnable: Boolean = false)

@Serializable
internal data class ImportCityChoice(
    val id: Long = 0,
    val name: String = "",
    val slug: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable
internal data class ImportFormDefaults(
    @SerialName("city_id") val cityId: Long? = null,
    val type: String = "ics",
    @SerialName("is_active") val isActive: Boolean = true,
)

/** I tetti del modulo, gli stessi di `ImportSourceRules` sul server. */
@Serializable
internal data class ImportFormLimits(
    @SerialName("max_url_length") val maxUrlLength: Int = 1000,
    @SerialName("max_mapping_entries") val maxMappingEntries: Int = 50,
    @SerialName("max_mapping_key_length") val maxMappingKeyLength: Int = 100,
    @SerialName("max_mapping_value_length") val maxMappingValueLength: Int = 1000,
)

/** Le scelte del modulo di una sorgente: `GET import-sources/form`. */
@Serializable
internal data class ImportSourceFormOptions(
    val types: List<ImportTypeChoice> = emptyList(),
    val cities: List<ImportCityChoice> = emptyList(),
    val categories: List<SystemRef> = emptyList(),
    val defaults: ImportFormDefaults = ImportFormDefaults(),
    val limits: ImportFormLimits = ImportFormLimits(),
)

@Serializable
internal data class ImportVenueChoice(val id: Long = 0, val name: String = "", val city: String? = null)

@Serializable
internal data class ImportRunQueued(val id: Long = 0, val queued: Boolean = false)

/** Un invio previsto: le colonne della tabella del pannello, anche quelle nascoste di partenza. */
@Serializable
internal data class ScheduledNotificationRow(
    val id: Long = 0,
    @SerialName("send_at") val sendAt: String? = null,
    val type: String = "",
    @SerialName("type_label") val typeLabel: String = "",
    val recipient: String? = null,
    val subject: String = "",
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    val channel: String = "",
    @SerialName("channel_label") val channelLabel: String = "",
    val attempts: Int = 0,
    @SerialName("sent_at") val sentAt: String? = null,
    val reason: String? = null,
    @SerialName("dedupe_key") val dedupeKey: String? = null,
    val abilities: JsonElement? = null,
) {
    val pending: Boolean get() = status == NotificationStatusValue.PENDING
}

@Serializable
internal data class CronInstalled(val readable: Boolean = false, val scheduler: Boolean = false, val worker: Boolean = false)

@Serializable
internal data class CronLines(val scheduler: String = "", val worker: String = "")

@Serializable
internal data class SocialAutopost(
    @SerialName("city_id") val cityId: Long? = null,
    val city: String? = null,
    @SerialName("telegram_verified") val telegramVerified: Boolean = false,
    @SerialName("verified_at") val verifiedAt: String? = null,
    @SerialName("instagram_enabled") val instagramEnabled: Boolean = false,
    val automatic: Boolean = false,
    @SerialName("publish_time") val publishTime: String? = null,
)

@Serializable
internal data class ScheduledTask(
    val name: String = "",
    val explanation: String = "",
    val command: String = "",
    val expression: String = "",
    @SerialName("next_due_date") val nextDueDate: String? = null,
    @SerialName("last_finished") val lastFinished: String? = null,
    @SerialName("last_failed") val lastFailed: String? = null,
    val monitored: Boolean = false,
)

/** «Cron e programmazioni»: la pagina di sola lettura del pannello, come dati. */
@Serializable
internal data class SchedulerOverviewData(
    val installed: CronInstalled = CronInstalled(),
    val cron: CronLines = CronLines(),
    val queue: String = "",
    val social: List<SocialAutopost> = emptyList(),
    val tasks: List<ScheduledTask> = emptyList(),
)

// ------------------------------------------------------------------ mappatura

/**
 * Una voce della mappatura nel modulo: chiave e valore come testo. `list`
 * vale per gli elenchi (`exclude_keywords`), che si scrivono separati da
 * virgole e tornano al server come elenco.
 */
internal data class MappingEntry(val key: String = "", val value: String = "", val list: Boolean = false)

/**
 * Le voci modificabili di una mappatura e quelle che il modulo non sa
 * mostrare (dizionari annidati), che si conservano così come sono.
 */
internal data class MappingDraft(val entries: List<MappingEntry> = emptyList(), val preserved: Map<String, JsonElement> = emptyMap())

internal fun mappingDraft(element: JsonElement?): MappingDraft {
    val obj = element as? JsonObject ?: return MappingDraft()
    val entries = mutableListOf<MappingEntry>()
    val preserved = linkedMapOf<String, JsonElement>()
    obj.forEach { (key, value) ->
        when (value) {
            is JsonNull -> entries += MappingEntry(key, "")
            is JsonPrimitive -> entries += MappingEntry(key, value.contentOrNull.orEmpty())
            is JsonArray -> if (value.all { it is JsonPrimitive && it !is JsonNull }) {
                entries += MappingEntry(key, value.joinToString(", ") { (it as JsonPrimitive).content }, list = true)
            } else preserved[key] = value
            is JsonObject -> preserved[key] = value
        }
    }
    return MappingDraft(entries, preserved)
}

/** La mappatura da mandare: voci senza chiave scartate, elenchi divisi sulle virgole; `null` se vuota. */
internal fun mappingJson(draft: MappingDraft): JsonObject? {
    val out = linkedMapOf<String, JsonElement>()
    draft.preserved.forEach { (key, value) -> out[key] = value }
    draft.entries.forEach { entry ->
        val key = entry.key.trim()
        if (key.isEmpty()) return@forEach
        out[key] = if (entry.list) {
            JsonArray(entry.value.split(',').map { it.trim() }.filter { it.isNotEmpty() }.map(::JsonPrimitive))
        } else JsonPrimitive(entry.value)
    }
    return if (out.isEmpty()) null else JsonObject(out)
}
