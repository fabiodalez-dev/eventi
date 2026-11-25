package it.fabiodalez.incitta.data.management

import android.content.ContentResolver
import android.net.Uri
import android.provider.OpenableColumns
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import java.io.IOException
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.add

/*
 * P9 — il calendario collegato del locale (W-IM-01…07). Le forme sono quelle
 * di `CalendarImportController`; ogni campo ha un valore predefinito, così un
 * server più nuovo non rompe la lettura.
 */

/** I due tipi del modulo, con i valori del server. */
internal enum class CalendarSourceType(val value: String) {
    Ics("ics"), Csv("csv");

    companion object {
        fun from(value: String?): CalendarSourceType = entries.firstOrNull { it.value == value } ?: Ics
    }
}

/** `GET V/import`: lo stato del collegamento e i valori con cui aprire il modulo. */
@Serializable
internal data class CalendarImportState(
    val source: CalendarSource? = null,
    val form: CalendarForm = CalendarForm(),
    @SerialName("default_exclude_keywords") val defaultExcludeKeywords: List<String> = emptyList(),
    val limits: CalendarLimits = CalendarLimits(),
)

/** La sorgente collegata, come nel riquadro «Stato del collegamento» della pagina. */
@Serializable
internal data class CalendarSource(
    val id: Long = 0,
    val type: String = "ics",
    @SerialName("type_label") val typeLabel: String = "",
    /* L'indirizzo così come lo mostra la pagina; per un file caricato `url` è nullo e `is_upload` vero. */
    val address: String = "",
    val url: String? = null,
    @SerialName("is_upload") val isUpload: Boolean = false,
    @SerialName("is_active") val isActive: Boolean = false,
    @SerialName("last_run_at") val lastRunAt: String? = null,
    @SerialName("last_status") val lastStatus: String? = null,
    @SerialName("last_status_label") val lastStatusLabel: String? = null,
    @SerialName("last_error") val lastError: String? = null,
    @SerialName("can_run") val canRun: Boolean = false,
    @SerialName("can_update") val canUpdate: Boolean = false,
    @SerialName("can_delete") val canDelete: Boolean = false,
)

@Serializable
internal data class CalendarForm(
    val type: String = "ics",
    val url: String? = null,
    @SerialName("exclude_keywords") val excludeKeywords: List<String> = emptyList(),
)

@Serializable
internal data class CalendarLimits(
    @SerialName("max_keywords") val maxKeywords: Int = 100,
    @SerialName("max_keyword_length") val maxKeywordLength: Int = 100,
    @SerialName("max_url_length") val maxUrlLength: Int = 1000,
    @SerialName("max_file_bytes") val maxFileBytes: Long = 8L * 1024 * 1024,
    @SerialName("preview_size") val previewSize: Int = 20,
)

/** `POST V/import/preview`: le prime date che entrerebbero, oppure l'errore del calendario, e il segno per collegare. */
@Serializable
internal data class CalendarPreview(
    val rows: List<CalendarPreviewRow> = emptyList(),
    val error: String? = null,
    @SerialName("preview_token") val previewToken: String? = null,
    @SerialName("expires_in_minutes") val expiresInMinutes: Int = 60,
)

@Serializable
internal data class CalendarPreviewRow(
    val title: String = "",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    /* Lo stesso testo della tabella della pagina, nell'ora della città: «05/09/2026 21:30». */
    val `when`: String = "",
    val where: String? = null,
    @SerialName("all_day") val allDay: Boolean = false,
    val recurring: Boolean = false,
    val cancelled: Boolean = false,
    val notes: List<String> = emptyList(),
)

/** Collegamento, sospensione, ripresa e scollegamento: lo stato nuovo e il messaggio da mostrare. */
@Serializable
internal data class CalendarChange(val data: CalendarImportState = CalendarImportState(), val message: String = "")

/** `POST V/import/run`: l'esito di «Leggi adesso». `outcome` è `success`, `partial` o `failed`. */
@Serializable
internal data class CalendarRunResult(
    val outcome: String = "",
    @SerialName("outcome_label") val outcomeLabel: String = "",
    val title: String = "",
    val message: String = "",
    val state: CalendarImportState = CalendarImportState(),
) {
    val failed: Boolean get() = outcome == "failed"
}

/** Quello che si manda al server per l'anteprima e il collegamento: il modulo della pagina. */
internal data class CalendarDraft(
    val type: CalendarSourceType,
    val url: String,
    val keywords: List<String>,
)

/** Il CSV scelto con il selettore di sistema, già letto: si manda in multipart nel campo `file`. */
internal class CalendarFile(val name: String, val bytes: ByteArray) {
    val size: Int get() = bytes.size
}

/** Un file che non si può mandare: illeggibile o oltre il tetto del server. */
internal class CalendarFileException(val reason: Reason) : IOException(reason.name) {
    enum class Reason { Unreadable, TooLarge, Empty }
}

/**
 * Legge il file scelto, al massimo `maxBytes` (il tetto del server): oltre si
 * ferma senza tenere in memoria il resto. Va chiamata fuori dal thread
 * principale.
 */
internal fun readCalendarFile(resolver: ContentResolver, uri: Uri, maxBytes: Long): CalendarFile {
    val name = runCatching {
        resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { cursor ->
            if (cursor.moveToFirst()) cursor.getString(0) else null
        }
    }.getOrNull()?.takeIf { it.isNotBlank() } ?: "calendario.csv"
    val bytes = try {
        resolver.openInputStream(uri)?.use { input -> readAtMost(input, maxBytes) }
    } catch (e: CalendarFileException) {
        throw e
    } catch (_: Exception) {
        null
    } ?: throw CalendarFileException(CalendarFileException.Reason.Unreadable)
    if (bytes.isEmpty()) throw CalendarFileException(CalendarFileException.Reason.Empty)
    return CalendarFile(name, bytes)
}

/** I byte del flusso, o `TooLarge` appena si supera il tetto. */
internal fun readAtMost(input: java.io.InputStream, maxBytes: Long): ByteArray {
    val output = java.io.ByteArrayOutputStream()
    val buffer = ByteArray(8 * 1024)
    var total = 0L
    while (true) {
        val read = input.read(buffer)
        if (read < 0) break
        total += read
        if (total > maxBytes) throw CalendarFileException(CalendarFileException.Reason.TooLarge)
        output.write(buffer, 0, read)
    }
    return output.toByteArray()
}

/** Le chiamate di P9. È un'interfaccia perché i ViewModel si provano con un finto, senza rete. */
internal interface ManagementCalendarImportSource {
    suspend fun state(venueId: Long): CalendarImportState
    suspend fun preview(venueId: Long, draft: CalendarDraft, file: CalendarFile?): CalendarPreview
    suspend fun connect(venueId: Long, draft: CalendarDraft, file: CalendarFile?, previewToken: String): CalendarChange
    suspend fun run(venueId: Long): CalendarRunResult
    suspend fun pause(venueId: Long): CalendarChange
    suspend fun resume(venueId: Long): CalendarChange
    suspend fun disconnect(venueId: Long): CalendarChange
}

internal class ManagementCalendarImportApi(private val api: ApiClient, private val token: String) : ManagementCalendarImportSource {
    override suspend fun state(venueId: Long): CalendarImportState =
        api.get<ApiEnvelope<CalendarImportState>>(importPath(venueId), token).data

    override suspend fun preview(venueId: Long, draft: CalendarDraft, file: CalendarFile?): CalendarPreview =
        if (file != null && draft.type == CalendarSourceType.Csv) {
            calendarJson.decodeFromJsonElement(ApiEnvelope.serializer(CalendarPreview.serializer()), upload("${importPath(venueId)}/preview", draft, file, null)).data
        } else {
            api.post<ApiEnvelope<CalendarPreview>, JsonObject>("${importPath(venueId)}/preview", calendarDraftBody(draft, null), token).data
        }

    override suspend fun connect(venueId: Long, draft: CalendarDraft, file: CalendarFile?, previewToken: String): CalendarChange =
        if (file != null && draft.type == CalendarSourceType.Csv) {
            calendarJson.decodeFromJsonElement(CalendarChange.serializer(), upload(importPath(venueId), draft, file, previewToken))
        } else {
            api.post<CalendarChange, JsonObject>(importPath(venueId), calendarDraftBody(draft, previewToken), token)
        }

    override suspend fun run(venueId: Long): CalendarRunResult =
        api.post<ApiEnvelope<CalendarRunResult>, JsonObject>("${importPath(venueId)}/run", buildJsonObject {}, token).data

    override suspend fun pause(venueId: Long): CalendarChange =
        api.post<CalendarChange, JsonObject>("${importPath(venueId)}/pause", buildJsonObject {}, token)

    override suspend fun resume(venueId: Long): CalendarChange =
        api.post<CalendarChange, JsonObject>("${importPath(venueId)}/resume", buildJsonObject {}, token)

    override suspend fun disconnect(venueId: Long): CalendarChange =
        api.delete<CalendarChange>(importPath(venueId), token)

    private suspend fun upload(path: String, draft: CalendarDraft, file: CalendarFile, previewToken: String?): JsonObject =
        api.upload(path, token, FILE_FIELD, file.bytes, CSV_MIME, uploadName(file.name), calendarMultipartFields(draft, previewToken))

    private fun importPath(venueId: Long) = "management/${SubjectKind.Venue.segment}/$venueId/import"

    companion object {
        const val FILE_FIELD = "file"
        const val CSV_MIME = "text/csv"
        private val calendarJson = Json { ignoreUnknownKeys = true; explicitNulls = false; coerceInputValues = true }

        /** Il nome nel multipart: quello del file, con l'estensione `.csv` se manca. */
        internal fun uploadName(name: String): String {
            val clean = name.substringAfterLast('/').ifBlank { "calendario.csv" }
            return if (clean.lowercase().endsWith(".csv") || clean.lowercase().endsWith(".txt")) clean else "$clean.csv"
        }
    }
}

/** Il modulo in JSON: tipo, indirizzo, parole escluse e, per collegare, il segno dell'anteprima. */
internal fun calendarDraftBody(draft: CalendarDraft, previewToken: String?): JsonObject = buildJsonObject {
    put("type", draft.type.value)
    draft.url.trim().takeIf { it.isNotEmpty() }?.let { put("url", it) }
    putJsonArray("exclude_keywords") { draft.keywords.forEach { add(it) } }
    previewToken?.let { put("preview_token", it) }
}

/** Gli stessi campi in multipart, accanto al file: le parole come `exclude_keywords[]`. */
internal fun calendarMultipartFields(draft: CalendarDraft, previewToken: String?): List<Pair<String, String>> = buildList {
    add("type" to draft.type.value)
    draft.url.trim().takeIf { it.isNotEmpty() }?.let { add("url" to it) }
    draft.keywords.forEach { add("exclude_keywords[]" to it) }
    previewToken?.let { add("preview_token" to it) }
}
