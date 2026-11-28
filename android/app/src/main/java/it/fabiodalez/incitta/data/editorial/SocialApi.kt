package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.SOCIAL_PAGE_SIZE
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.data.management.socialFileName
import it.fabiodalez.incitta.data.management.socialPrepareBody
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import java.io.File
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonObject

/*
 * Le chiamate del Social della redazione (R9), sotto `E/social`. Le
 * schermate le fanno dentro `repository.call { }`, che si occupa dei 423.
 * Studio, «Social di oggi» e storico lavorano sulla città di lavoro
 * (`?city=`, come il selettore «Città» della pagina); un pacchetto, una
 * pubblicazione e le impostazioni si trovano per chiave, senza città.
 */
internal interface EditorialSocialSource {
    suspend fun catalog(city: String?, query: SocialCatalogQuery): SocialCatalogPage
    suspend fun calendar(city: String?, month: String, date: String, eventId: Long?, search: String): SocialCalendar
    suspend fun day(city: String?, date: String?, eventId: Long?): SocialDay
    suspend fun today(city: String?): SocialToday
    suspend fun prepareToday(city: String?): EditorialSocialBatch
    suspend fun recent(city: String?): List<SocialBatchSummary>
    suspend fun prepare(city: String?, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): EditorialSocialBatch
    suspend fun batch(id: String): EditorialSocialBatch
    suspend fun image(batchId: String, item: SocialBatchItem, directory: File): DownloadedFile
    suspend fun zip(batch: EditorialSocialBatch, directory: File): DownloadedFile
    /** «Pubblica sui canali collegati»: le righe dello storico del pacchetto. */
    suspend fun publish(batchId: String): SocialResult<List<SocialPublicationRow>>
    /** «Programma»: `local` è `Y-m-dTH:i` nel fuso della città del pacchetto. */
    suspend fun schedule(batchId: String, local: String): SocialResult<List<SocialPublicationRow>>
    suspend fun publications(city: String?, params: List<Pair<String, String>>): EditorialPage<SocialPublicationRow>
    suspend fun reschedule(id: Long, local: String): SocialResult<SocialPublicationRow>
    suspend fun cancel(id: Long): SocialResult<SocialPublicationRow>
    suspend fun retry(id: Long): SocialResult<SocialPublicationRow>
    suspend fun settings(): SocialSettings
    suspend fun saveSettings(values: SocialSettingsValues): SocialResult<SocialSettings>
    suspend fun verifyMeta(): SocialResult<SocialSettings>
    suspend fun verifyTelegram(): SocialResult<SocialSettings>
    suspend fun metaLink(): SocialMetaLink
    suspend fun disconnectMeta(): SocialResult<SocialSettings>
}

@Serializable private data class SocialRecentEnvelope(val data: List<SocialBatchSummary> = emptyList())
@Serializable private data class ScheduleBody(@kotlinx.serialization.SerialName("scheduled_at") val scheduledAt: String)

internal class EditorialSocialApi(private val api: ApiClient, private val token: String) : EditorialSocialSource {
    override suspend fun catalog(city: String?, query: SocialCatalogQuery): SocialCatalogPage =
        api.get(editorialSocialCatalogPath(city, query), token)

    override suspend fun calendar(city: String?, month: String, date: String, eventId: Long?, search: String): SocialCalendar =
        api.get(editorialSocialPath("calendar", city, listOf("month" to month, "date" to date, "search" to search.trim(), "event" to eventId?.toString())), token)

    override suspend fun day(city: String?, date: String?, eventId: Long?): SocialDay =
        api.get(editorialSocialPath("day", city, listOf("date" to date, "event" to eventId?.toString())), token)

    override suspend fun today(city: String?): SocialToday = api.get(editorialSocialPath("today", city), token)

    override suspend fun prepareToday(city: String?): EditorialSocialBatch =
        api.post<ApiEnvelope<EditorialSocialBatch>, JsonObject>(editorialSocialPath("today", city), JsonObject(emptyMap()), token).data

    override suspend fun recent(city: String?): List<SocialBatchSummary> =
        api.get<SocialRecentEnvelope>(editorialSocialPath("batches", city), token).data

    override suspend fun prepare(city: String?, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): EditorialSocialBatch =
        api.post<ApiEnvelope<EditorialSocialBatch>, JsonObject>(editorialSocialPath("batches", city), socialPrepareBody(date, occurrences, options, eventId), token).data

    override suspend fun batch(id: String): EditorialSocialBatch =
        api.get<ApiEnvelope<EditorialSocialBatch>>(editorialSocialPath("batches/$id", null), token).data

    override suspend fun image(batchId: String, item: SocialBatchItem, directory: File): DownloadedFile =
        api.download(editorialSocialPath("batches/$batchId/images/${item.index}", null), token, File(directory, socialFileName(item.filename, "jpg")))

    override suspend fun zip(batch: EditorialSocialBatch, directory: File): DownloadedFile =
        api.download(editorialSocialPath("batches/${batch.id}/zip", null), token, File(directory, "incitta-${batch.date}-${batch.format.value}.zip"))

    override suspend fun publish(batchId: String): SocialResult<List<SocialPublicationRow>> =
        api.post(editorialSocialPath("batches/$batchId/publish", null), JsonObject(emptyMap()), token)

    override suspend fun schedule(batchId: String, local: String): SocialResult<List<SocialPublicationRow>> =
        api.post(editorialSocialPath("batches/$batchId/schedule", null), ScheduleBody(local), token)

    override suspend fun publications(city: String?, params: List<Pair<String, String>>): EditorialPage<SocialPublicationRow> =
        api.get(editorialPath("social/publications", city, params), token)

    override suspend fun reschedule(id: Long, local: String): SocialResult<SocialPublicationRow> =
        api.execute(editorialSocialPath("publications/$id", null), "PATCH", api.json.encodeToString(ScheduleBody.serializer(), ScheduleBody(local)), token)

    override suspend fun cancel(id: Long): SocialResult<SocialPublicationRow> =
        api.post(editorialSocialPath("publications/$id/cancel", null), JsonObject(emptyMap()), token)

    override suspend fun retry(id: Long): SocialResult<SocialPublicationRow> =
        api.post(editorialSocialPath("publications/$id/retry", null), JsonObject(emptyMap()), token)

    override suspend fun settings(): SocialSettings =
        api.get<ApiEnvelope<SocialSettings>>(editorialSocialPath("settings", null), token).data

    override suspend fun saveSettings(values: SocialSettingsValues): SocialResult<SocialSettings> =
        api.execute(editorialSocialPath("settings", null), "PUT", socialSettingsBody(values).toString(), token)

    override suspend fun verifyMeta(): SocialResult<SocialSettings> =
        api.post(editorialSocialPath("settings/verify", null), JsonObject(emptyMap()), token)

    override suspend fun verifyTelegram(): SocialResult<SocialSettings> =
        api.post(editorialSocialPath("settings/telegram/verify", null), JsonObject(emptyMap()), token)

    override suspend fun metaLink(): SocialMetaLink =
        api.get<ApiEnvelope<SocialMetaLink>>(editorialSocialPath("meta/connect", null), token).data

    override suspend fun disconnectMeta(): SocialResult<SocialSettings> =
        api.post(editorialSocialPath("meta/disconnect", null), JsonObject(emptyMap()), token)
}

/** L'API del Social per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.socialApi(): EditorialSocialSource =
    EditorialSocialApi(client ?: throw IllegalStateException("Social non disponibile in questa sessione"), token)

/** Un percorso del Social della redazione, con i parametri non vuoti e la città. */
internal fun editorialSocialPath(tail: String, city: String?, params: List<Pair<String, String?>> = emptyList()): String =
    editorialPath("social/$tail", city, params.mapNotNull { (key, value) -> value?.takeIf { it.isNotBlank() }?.let { key to it } })

internal fun editorialSocialCatalogPath(city: String?, query: SocialCatalogQuery): String = editorialSocialPath("catalog", city, listOf(
    "period" to query.period,
    "from" to query.from.takeIf { query.period == "custom" },
    "until" to query.until.takeIf { query.period == "custom" },
    "search" to query.search.trim(),
    "limit" to query.limit.takeIf { it != SOCIAL_PAGE_SIZE }?.toString(),
    "event" to query.eventId?.toString(),
))

/** L'anteprima JPEG della grafica di una serata, con il token (la guarda `AuthorizedImage`). */
internal fun editorialSocialPreviewPath(occurrenceId: Long): String = editorialSocialPath("dates/$occurrenceId/preview", null)

/**
 * Il corpo di «Salva impostazioni»: tutti i campi del modulo, con `null`
 * per quelli vuoti (il server li svuota). I segreti non ci sono: il server
 * li rifiuterebbe.
 */
internal fun socialSettingsBody(values: SocialSettingsValues): JsonObject = buildJsonObject {
    put("app_id", values.appId?.trim()?.ifBlank { null })
    values.cityId?.let { put("city_id", it) }
    put("page_id", values.pageId?.trim()?.ifBlank { null })
    put("instagram_id", values.instagramId?.trim()?.ifBlank { null })
    put("graph_version", values.graphVersion.trim())
    put("facebook_enabled", values.facebookEnabled)
    put("instagram_enabled", values.instagramEnabled)
    put("telegram_chat_id", values.telegramChatId?.trim()?.ifBlank { null })
    put("telegram_enabled", values.telegramEnabled)
    put("automatic", values.automatic)
    putJsonObject("graphic_options") {
        put("monochrome", values.graphicOptions.monochrome)
        put("show_address", values.graphicOptions.showAddress)
        put("show_price", values.graphicOptions.showPrice)
        put("image_fit", values.graphicOptions.imageFit)
    }
    put("publish_time", values.publishTime.trim())
    put("caption", values.caption)
}
