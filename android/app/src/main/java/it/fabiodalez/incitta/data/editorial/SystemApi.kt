package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import kotlinx.serialization.json.JsonObject

/**
 * Le chiamate del gruppo «Sistema» (R12). Le schermate le fanno dentro
 * `repository.call { }`, che si occupa dei 423 (sblocco e password recente
 * per l'eliminazione di una sorgente). La città non conta: il pannello vede
 * sorgenti e invii di tutte le città, e il server anche.
 *
 * Il corpo del modulo di una sorgente è un `JsonObject` già pronto: in una
 * modifica vanno solo i campi cambiati, e un locale tolto deve arrivare come
 * `null` esplicito (il client JSON dell'app altrimenti i `null` li omette).
 */
internal interface SystemSource {
    suspend fun importSources(params: List<Pair<String, String>>): EditorialPage<EditorialImportSource>
    suspend fun importSource(id: Long): EditorialImportSource
    suspend fun importForm(): ImportSourceFormOptions
    suspend fun importVenues(query: String): List<ImportVenueChoice>
    suspend fun createImportSource(body: JsonObject): ModerationResult<EditorialImportSource>
    suspend fun updateImportSource(id: Long, body: JsonObject): ModerationResult<EditorialImportSource>
    /** Pericolosa: 423 `PASSWORD_REQUIRED` senza la password degli ultimi 15 minuti. */
    suspend fun deleteImportSource(id: Long): ModerationResult<DeletedRow>
    suspend fun previewImport(id: Long): ImportPreview
    suspend fun runImport(id: Long): ModerationResult<ImportRunQueued>
    suspend fun importRuns(id: Long, params: List<Pair<String, String>>): EditorialPage<ImportRunRow>

    suspend fun scheduledNotifications(params: List<Pair<String, String>>): EditorialPage<ScheduledNotificationRow>
    suspend fun cancelNotification(id: Long): ModerationResult<ScheduledNotificationRow>

    suspend fun scheduledOperations(): SchedulerOverviewData
}

internal class SystemApi(private val api: ApiClient, private val token: String) : SystemSource {
    override suspend fun importSources(params: List<Pair<String, String>>): EditorialPage<EditorialImportSource> =
        api.get(editorialPath("import-sources", null, params), token)

    override suspend fun importSource(id: Long): EditorialImportSource =
        api.get<ApiEnvelope<EditorialImportSource>>(editorialPath("import-sources/$id", null), token).data

    override suspend fun importForm(): ImportSourceFormOptions =
        api.get<ApiEnvelope<ImportSourceFormOptions>>(editorialPath("import-sources/form", null), token).data

    override suspend fun importVenues(query: String): List<ImportVenueChoice> =
        api.get<ApiEnvelope<List<ImportVenueChoice>>>(editorialPath("import-sources/venues", null, listOf("q" to query.trim()).filter { it.second.isNotEmpty() }), token).data

    override suspend fun createImportSource(body: JsonObject): ModerationResult<EditorialImportSource> =
        api.execute(editorialPath("import-sources", null), "POST", body.toString(), token)

    override suspend fun updateImportSource(id: Long, body: JsonObject): ModerationResult<EditorialImportSource> =
        api.execute(editorialPath("import-sources/$id", null), "PATCH", body.toString(), token)

    override suspend fun deleteImportSource(id: Long): ModerationResult<DeletedRow> =
        api.delete(editorialPath("import-sources/$id", null), token)

    override suspend fun previewImport(id: Long): ImportPreview =
        api.execute<ApiEnvelope<ImportPreview>>(editorialPath("import-sources/$id/preview", null), "POST", "{}", token).data

    override suspend fun runImport(id: Long): ModerationResult<ImportRunQueued> =
        api.execute(editorialPath("import-sources/$id/run", null), "POST", "{}", token)

    override suspend fun importRuns(id: Long, params: List<Pair<String, String>>): EditorialPage<ImportRunRow> =
        api.get(editorialPath("import-sources/$id/runs", null, params), token)

    override suspend fun scheduledNotifications(params: List<Pair<String, String>>): EditorialPage<ScheduledNotificationRow> =
        api.get(editorialPath("scheduled-notifications", null, params), token)

    override suspend fun cancelNotification(id: Long): ModerationResult<ScheduledNotificationRow> =
        api.execute(editorialPath("scheduled-notifications/$id/cancel", null), "POST", "{}", token)

    override suspend fun scheduledOperations(): SchedulerOverviewData =
        api.get<ApiEnvelope<SchedulerOverviewData>>(editorialPath("scheduled-operations", null), token).data
}

/** L'API del sistema per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.systemApi(): SystemSource =
    SystemApi(client ?: throw IllegalStateException("Sistema non disponibile in questa sessione"), token)
