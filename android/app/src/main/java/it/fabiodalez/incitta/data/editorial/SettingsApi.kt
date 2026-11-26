package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import kotlinx.serialization.json.JsonObject

/**
 * Le chiamate della configurazione tecnica (R13). Le schermate le fanno dentro
 * `repository.call { }`, che si occupa dei 423. La città non conta: il
 * pannello vede le stesse impostazioni da ogni città, e il server anche.
 *
 * Decisione 5 del piano: l'app legge tutto e fa le azioni operative (svuota e
 * verifica la cache, prova della posta); scrive testi delle email,
 * newsletter, cookie e indirizzi cambiati. SMTP, script di consenso e Redis
 * si scrivono solo dal sito.
 */
internal interface SettingsSource {
    suspend fun texts(): EmailTexts
    suspend fun previewText(key: String, value: String): TextPreview
    /** 422 su `revision` se nel frattempo qualcuno l'ha cambiato, su `value` per un segnaposto sconosciuto. */
    suspend fun updateText(key: String, value: String, revision: String): SettingsResult<EmailText>
    suspend fun resetTexts(): SettingsResult<EmailTexts>

    suspend fun mail(): MailSettingsView
    suspend fun testMail(): SettingsResult<MailTestOutcome>

    suspend fun newsletter(): NewsletterSettingsView
    suspend fun saveNewsletter(body: NewsletterBody): SettingsResult<NewsletterSettingsView>

    suspend fun scripts(params: List<Pair<String, String>>): EditorialPage<ConsentScriptRow>
    suspend fun script(id: Long): ConsentScriptRow

    suspend fun cookies(params: List<Pair<String, String>>): CookiePage
    suspend fun addCookie(body: CookieBody): SettingsResult<CookieRow>
    suspend fun reorderCookies(category: String, ids: List<Long>): SettingsResult<List<CookieRow>>

    suspend fun redirects(params: List<Pair<String, String>>): EditorialPage<RedirectRow>
    suspend fun redirectOptions(): RedirectOptions
    suspend fun addRedirect(body: RedirectBody): SettingsResult<RedirectRow>

    suspend fun cache(): CacheSettingsView
    suspend fun clearCache(): SettingsResult<CacheCleared>
    suspend fun verifyRedis(): SettingsResult<CacheVerified>
}

internal class SettingsApi(private val api: ApiClient, private val token: String) : SettingsSource {
    override suspend fun texts(): EmailTexts = api.get<ApiEnvelope<EmailTexts>>(editorialPath("notification-texts", null), token).data

    override suspend fun previewText(key: String, value: String): TextPreview =
        api.post<ApiEnvelope<TextPreview>, TextPreviewBody>(editorialPath("notification-texts/preview", null), TextPreviewBody(key, value), token).data

    override suspend fun updateText(key: String, value: String, revision: String): SettingsResult<EmailText> =
        api.execute(editorialPath("notification-texts/$key", null), "PUT", api.json.encodeToString(TextUpdateBody.serializer(), TextUpdateBody(value, revision)), token)

    override suspend fun resetTexts(): SettingsResult<EmailTexts> =
        api.post(editorialPath("notification-texts/reset", null), JsonObject(emptyMap()), token)

    override suspend fun mail(): MailSettingsView = api.get<ApiEnvelope<MailSettingsView>>(editorialPath("mail", null), token).data

    override suspend fun testMail(): SettingsResult<MailTestOutcome> = api.post(editorialPath("mail/test", null), JsonObject(emptyMap()), token)

    override suspend fun newsletter(): NewsletterSettingsView = api.get<ApiEnvelope<NewsletterSettingsView>>(editorialPath("newsletter", null), token).data

    override suspend fun saveNewsletter(body: NewsletterBody): SettingsResult<NewsletterSettingsView> =
        api.execute(editorialPath("newsletter", null), "PUT", api.json.encodeToString(NewsletterBody.serializer(), body), token)

    override suspend fun scripts(params: List<Pair<String, String>>): EditorialPage<ConsentScriptRow> =
        api.get(editorialPath("consent-scripts", null, params), token)

    override suspend fun script(id: Long): ConsentScriptRow = api.get<ApiEnvelope<ConsentScriptRow>>(editorialPath("consent-scripts/$id", null), token).data

    override suspend fun cookies(params: List<Pair<String, String>>): CookiePage = api.get(editorialPath("cookies", null, params), token)

    override suspend fun addCookie(body: CookieBody): SettingsResult<CookieRow> = api.post(editorialPath("cookies", null), body, token)

    override suspend fun reorderCookies(category: String, ids: List<Long>): SettingsResult<List<CookieRow>> =
        api.post(editorialPath("cookies/order", null), CookieOrderBody(category, ids), token)

    override suspend fun redirects(params: List<Pair<String, String>>): EditorialPage<RedirectRow> =
        api.get(editorialPath("redirects", null, params), token)

    override suspend fun redirectOptions(): RedirectOptions = api.get<ApiEnvelope<RedirectOptions>>(editorialPath("redirects/options", null), token).data

    override suspend fun addRedirect(body: RedirectBody): SettingsResult<RedirectRow> = api.post(editorialPath("redirects", null), body, token)

    override suspend fun cache(): CacheSettingsView = api.get<ApiEnvelope<CacheSettingsView>>(editorialPath("cache", null), token).data

    override suspend fun clearCache(): SettingsResult<CacheCleared> = api.post(editorialPath("cache/clear", null), JsonObject(emptyMap()), token)

    override suspend fun verifyRedis(): SettingsResult<CacheVerified> = api.post(editorialPath("cache/verify", null), JsonObject(emptyMap()), token)
}

/** L'API della configurazione tecnica per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.settingsApi(): SettingsSource =
    SettingsApi(client ?: throw IllegalStateException("Configurazione tecnica non disponibile in questa sessione"), token)
