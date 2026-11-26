package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.Poster
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventSourceInfo
import it.fabiodalez.incitta.data.management.LabeledValue
import it.fabiodalez.incitta.data.management.ManagedEventDate
import it.fabiodalez.incitta.data.management.ManagedEventDates
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import java.io.File
import java.time.ZoneId
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray

/*
 * R2 — gli eventi della redazione: elenco, scheda, decisioni, cronologia,
 * anteprima, esportazione e proposte dal pubblico, sotto
 * `/api/v1/editorial/events` e `/api/v1/editorial/submissions`. Ogni campo
 * ha un valore predefinito: un server diverso non deve far fallire la
 * lettura. Le azioni permesse arrivano in `abilities` (calcolate dal server
 * con le policy del pannello).
 */

/** Una città in breve, con il fuso in cui si leggono le date dell'evento. */
@Serializable
internal data class EditorialEventCity(val id: Long = 0, val name: String = "", val timezone: String = "Europe/Rome") {
    val zone: ZoneId get() = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
}

/** Una scelta di un filtro o di un modulo: `{value, label}`. */
@Serializable
internal data class EditorialChoice(val value: String = "", val label: String = "") {
    fun toOption(): OptionChoice = OptionChoice(value, label)
}

/** Una riga dell'elenco eventi della redazione (`GET E/events`). */
@Serializable
internal data class EditorialEventRow(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val source: EventSourceInfo = EventSourceInfo(),
    val poster: Poster? = null,
    @SerialName("next_starts_at") val nextStartsAt: String? = null,
    @SerialName("last_starts_at") val lastStartsAt: String? = null,
    val past: Boolean = false,
    val venue: NamedRef? = null,
    val city: EditorialEventCity? = null,
    val category: NamedRef? = null,
    val place: String? = null,
    @SerialName("occurrences_count") val occurrencesCount: Int = 0,
    @SerialName("published_at") val publishedAt: String? = null,
    @SerialName("scheduled_publish_at") val scheduledPublishAt: String? = null,
    @SerialName("is_featured") val isFeatured: Boolean = false,
    @SerialName("featured_until") val featuredUntil: String? = null,
    val trashed: Boolean = false,
    val abilities: JsonElement? = null,
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
}

/** Il riquadro «Redazione» della scheda: provenienza, verifica, chi e quando. */
@Serializable
internal data class EditorialEventInfo(
    val source: String? = null,
    val verification: String? = null,
    @SerialName("created_by") val createdBy: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
    @SerialName("published_at") val publishedAt: String? = null,
    @SerialName("scheduled_publish_at") val scheduledPublishAt: String? = null,
    @SerialName("editorial_score") val editorialScore: Int = 0,
    @SerialName("rejection_reason") val rejectionReason: String? = null,
)

/** Quanto tocca «Annulla evento»: le date future ancora in programma e quante persone le hanno salvate. */
@Serializable
internal data class CancelImpact(
    @SerialName("upcoming_dates") val upcomingDates: Int = 0,
    @SerialName("saved_by") val savedBy: Int = 0,
)

/**
 * Le pagine collegate: percorsi del pannello (`admin/...`) da aprire sul
 * sito finché l'app non ha le loro schermate, e l'indirizzo pubblico.
 */
@Serializable
internal data class EditorialEventLinks(
    val statistics: String? = null,
    val social: String? = null,
    val sponsor: String? = null,
    val site: String? = null,
)

/** La scheda in sola lettura (`GET E/events/{id}`). */
@Serializable
internal data class EditorialEventDetail(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val source: EventSourceInfo = EventSourceInfo(),
    val poster: Poster? = null,
    val past: Boolean = false,
    val venue: NamedRef? = null,
    val city: EditorialEventCity = EditorialEventCity(),
    val category: NamedRef? = null,
    val organizer: String? = null,
    @SerialName("price_type") val priceType: LabeledValue = LabeledValue(),
    @SerialName("price_min") val priceMin: Double? = null,
    @SerialName("price_max") val priceMax: Double? = null,
    val tags: List<String> = emptyList(),
    val description: String? = null,
    @SerialName("description_text") val descriptionText: String? = null,
    @SerialName("next_date") val nextDate: ManagedEventDate? = null,
    val dates: ManagedEventDates = ManagedEventDates(),
    val trashed: Boolean = false,
    @SerialName("is_featured") val isFeatured: Boolean = false,
    @SerialName("featured_until") val featuredUntil: String? = null,
    @SerialName("scheduled_publish_at") val scheduledPublishAt: String? = null,
    val editorial: EditorialEventInfo = EditorialEventInfo(),
    @SerialName("cancel_impact") val cancelImpact: CancelImpact = CancelImpact(),
    val abilities: JsonElement? = null,
    val links: EditorialEventLinks = EditorialEventLinks(),
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
    /** «Approva programmazione» al posto di «Pubblica» quando la pubblicazione è programmata nel futuro. */
    fun approvesSchedule(now: java.time.Instant = java.time.Instant.now()): Boolean =
        scheduledPublishAt?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().isAfter(now) }.getOrDefault(false) } ?: false
}

/** La risposta di un'azione: la scheda aggiornata e il messaggio del pannello. */
@Serializable
internal data class EditorialEventResult(
    val data: EditorialEventDetail = EditorialEventDetail(),
    val message: String? = null,
    /* Solo dopo «Unisci»: il riepilogo di quanto è passato alla scheda che resta. */
    val detail: String? = null,
)

/** Le scelte dei filtri che non stanno in `options`: città, categorie, locali, provenienze, stati. */
@Serializable
internal data class EditorialEventFilters(
    val cities: List<EditorialChoice> = emptyList(),
    val categories: List<EditorialChoice> = emptyList(),
    val venues: List<EditorialChoice> = emptyList(),
    val sources: List<EditorialChoice> = emptyList(),
    val statuses: List<EditorialChoice> = emptyList(),
)

/** Un doppione possibile per «Unisci». */
@Serializable
internal data class MergeCandidate(val id: Long = 0, val title: String = "", val slug: String = "", val label: String = "")

@Serializable
internal data class BulkDeleteResult(val deleted: List<Long> = emptyList(), val skipped: Int = 0)

@Serializable
internal data class TrashedResult(val id: Long = 0, val trashed: Boolean = false)

@Serializable
internal data class TrashedEnvelope(val data: TrashedResult = TrashedResult(), val message: String? = null)

/** Una riga della cronologia delle modifiche. */
@Serializable
internal data class EditorialActivityRow(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val event: LabeledValue = LabeledValue(),
    val causer: String = "",
    val changes: String = "",
    @SerialName("restorable_fields") val restorableFields: List<String> = emptyList(),
    val abilities: JsonElement? = null,
) {
    val canRestore: Boolean get() = abilitiesOf(abilities)["restore"] == true
}

/** Una proposta dal pubblico nell'elenco. */
@Serializable
internal data class EditorialSubmissionRow(
    val id: Long = 0,
    val title: String = "",
    @SerialName("contact_name") val contactName: String? = null,
    @SerialName("starts_at_hint") val startsAtHint: String? = null,
    @SerialName("venue_hint") val venueHint: String? = null,
    val city: EditorialEventCity? = null,
    val status: LabeledValue = LabeledValue(),
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("event_id") val eventId: Long? = null,
    val abilities: JsonElement? = null,
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
}

/** Il modulo dell'evento da pubblicare, già compilato come nel pannello. */
@Serializable
internal data class SubmissionForm(
    val title: String = "",
    @SerialName("raw_text") val rawText: String? = null,
    @SerialName("venue_id") val venueId: Long? = null,
    @SerialName("category_id") val categoryId: Long? = null,
    @SerialName("starts_at_hint") val startsAtHint: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("price_type") val priceType: String = "unknown",
    @SerialName("price_min") val priceMin: Double? = null,
)

@Serializable
internal data class SubmissionChoices(
    val venues: List<EditorialChoice> = emptyList(),
    val categories: List<EditorialChoice> = emptyList(),
    @SerialName("price_types") val priceTypes: List<EditorialChoice> = emptyList(),
)

@Serializable
internal data class SubmissionLimits(val title: Int = 255, @SerialName("raw_text") val rawText: Int = 50000, val notes: Int = 2000)

/** «Esamina» (`GET E/submissions/{id}`). */
@Serializable
internal data class EditorialSubmissionDetail(
    val id: Long = 0,
    val title: String = "",
    @SerialName("contact_name") val contactName: String? = null,
    @SerialName("contact_email") val contactEmail: String? = null,
    @SerialName("starts_at_hint") val startsAtHint: String? = null,
    @SerialName("venue_hint") val venueHint: String? = null,
    val city: EditorialEventCity? = null,
    val status: LabeledValue = LabeledValue(),
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("event_id") val eventId: Long? = null,
    @SerialName("raw_text") val rawText: String? = null,
    val notes: String? = null,
    @SerialName("reviewed_at") val reviewedAt: String? = null,
    val form: SubmissionForm? = null,
    val choices: SubmissionChoices? = null,
    val timezone: String = "Europe/Rome",
    val limits: SubmissionLimits = SubmissionLimits(),
    val abilities: JsonElement? = null,
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
    val zone: ZoneId get() = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
}

@Serializable
internal data class SubmissionPublished(@SerialName("event_id") val eventId: Long = 0, val submission: EditorialSubmissionRow = EditorialSubmissionRow())

@Serializable
internal data class SubmissionPublishedEnvelope(val data: SubmissionPublished = SubmissionPublished(), val message: String? = null)

@Serializable
internal data class SubmissionRowEnvelope(val data: EditorialSubmissionRow = EditorialSubmissionRow(), val message: String? = null)

/** Le chiamate dell'area eventi, dietro un'interfaccia per provarle senza rete. */
internal interface EditorialEventsSource {
    suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialEventRow>
    suspend fun filters(): EditorialEventFilters
    suspend fun detail(id: Long): EditorialEventDetail
    suspend fun preview(id: Long): EventDetail
    suspend fun publish(id: Long): EditorialEventResult
    suspend fun unpublish(id: Long): EditorialEventResult
    suspend fun reject(id: Long, reason: String): EditorialEventResult
    suspend fun cancel(id: Long, note: String?): EditorialEventResult
    suspend fun feature(id: Long, featured: Boolean, until: String?): EditorialEventResult
    suspend fun duplicate(id: Long): EditorialEventResult
    suspend fun mergeCandidates(id: Long, query: String): List<MergeCandidate>
    suspend fun merge(id: Long, absorbed: Long): EditorialEventResult
    suspend fun destroy(id: Long): TrashedEnvelope
    suspend fun bulkDestroy(ids: List<Long>): BulkDeleteResult
    suspend fun restore(id: Long): EditorialEventResult
    suspend fun activity(id: Long, params: List<Pair<String, String>>): EditorialPage<EditorialActivityRow>
    suspend fun restoreRevision(id: Long, activityId: Long): EditorialEventResult
    suspend fun export(params: List<Pair<String, String>>, directory: File): DownloadedFile
    suspend fun submissions(params: List<Pair<String, String>>): EditorialPage<EditorialSubmissionRow>
    suspend fun submission(id: Long): EditorialSubmissionDetail
    suspend fun publishSubmission(id: Long, body: JsonObject): SubmissionPublishedEnvelope
    suspend fun rejectSubmission(id: Long, notes: String): SubmissionRowEnvelope
}

/**
 * L'API vera. `city` dà la città di lavoro scelta nel selettore: conta per
 * «oggi» (prossima data, pubblicati oggi, doppioni), non restringe l'elenco.
 */
internal class EditorialEventsApi(
    private val api: ApiClient,
    private val token: String,
    private val city: () -> String? = { null },
) : EditorialEventsSource {
    private fun path(path: String, params: List<Pair<String, String>> = emptyList()) = editorialPath(path, city(), params)

    override suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialEventRow> = api.get(path("events", params), token)
    override suspend fun filters(): EditorialEventFilters = api.get<ApiEnvelope<EditorialEventFilters>>(path("events/filters"), token).data
    override suspend fun detail(id: Long): EditorialEventDetail = api.get<ApiEnvelope<EditorialEventDetail>>(path("events/$id"), token).data
    override suspend fun preview(id: Long): EventDetail = api.get<ApiEnvelope<EventDetail>>(path("events/$id/preview"), token).data
    override suspend fun publish(id: Long): EditorialEventResult = action("events/$id/publish", JsonObject(emptyMap()))
    override suspend fun unpublish(id: Long): EditorialEventResult = action("events/$id/unpublish", JsonObject(emptyMap()))
    override suspend fun reject(id: Long, reason: String): EditorialEventResult = action("events/$id/reject", buildJsonObject { put("reason", reason) })
    override suspend fun cancel(id: Long, note: String?): EditorialEventResult =
        action("events/$id/cancel", buildJsonObject { note?.takeIf { it.isNotBlank() }?.let { put("note", it) } })
    override suspend fun feature(id: Long, featured: Boolean, until: String?): EditorialEventResult =
        action("events/$id/feature", buildJsonObject { put("featured", featured); if (featured && until != null) put("until", until) })
    override suspend fun duplicate(id: Long): EditorialEventResult = action("events/$id/duplicate", JsonObject(emptyMap()))
    override suspend fun mergeCandidates(id: Long, query: String): List<MergeCandidate> =
        api.get<ApiEnvelope<List<MergeCandidate>>>(path("events/$id/merge-candidates", listOf("q" to query)), token).data
    override suspend fun merge(id: Long, absorbed: Long): EditorialEventResult = action("events/$id/merge", buildJsonObject { put("absorbed", absorbed) })
    override suspend fun destroy(id: Long): TrashedEnvelope = api.delete(path("events/$id"), token)
    override suspend fun bulkDestroy(ids: List<Long>): BulkDeleteResult =
        api.post<ApiEnvelope<BulkDeleteResult>, JsonObject>(path("events/bulk-delete"), buildJsonObject { putJsonArray("ids") { ids.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } } }, token).data
    override suspend fun restore(id: Long): EditorialEventResult = action("events/$id/restore", JsonObject(emptyMap()))
    override suspend fun activity(id: Long, params: List<Pair<String, String>>): EditorialPage<EditorialActivityRow> = api.get(path("events/$id/activity", params), token)
    override suspend fun restoreRevision(id: Long, activityId: Long): EditorialEventResult = action("events/$id/activity/$activityId/restore", JsonObject(emptyMap()))
    override suspend fun export(params: List<Pair<String, String>>, directory: File): DownloadedFile =
        api.download(path("events/export", params), token, File(directory, "eventi-redazione-${System.currentTimeMillis()}.xlsx"))
    override suspend fun submissions(params: List<Pair<String, String>>): EditorialPage<EditorialSubmissionRow> = api.get(path("submissions", params), token)
    override suspend fun submission(id: Long): EditorialSubmissionDetail = api.get<ApiEnvelope<EditorialSubmissionDetail>>(path("submissions/$id"), token).data
    override suspend fun publishSubmission(id: Long, body: JsonObject): SubmissionPublishedEnvelope = api.post(path("submissions/$id/publish"), body, token)
    override suspend fun rejectSubmission(id: Long, notes: String): SubmissionRowEnvelope =
        api.post(path("submissions/$id/reject"), buildJsonObject { put("notes", notes) }, token)

    private suspend fun action(path: String, body: JsonObject): EditorialEventResult = api.post(path(path), body, token)

    companion object {
        /** L'API della sessione della redazione: client, token e città scelta del repository. */
        fun of(repository: EditorialRepository): EditorialEventsSource =
            EditorialEventsApi(repository.client ?: error("Nessun client HTTP"), repository.token) { repository.selectedCity }
    }
}
