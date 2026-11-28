package it.fabiodalez.incitta.ui.editorial.settings

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.CacheCleared
import it.fabiodalez.incitta.data.editorial.CacheSettingsView
import it.fabiodalez.incitta.data.editorial.CacheVerified
import it.fabiodalez.incitta.data.editorial.ConsentCategoryInfo
import it.fabiodalez.incitta.data.editorial.ConsentScriptRow
import it.fabiodalez.incitta.data.editorial.CookieBody
import it.fabiodalez.incitta.data.editorial.CookiePage
import it.fabiodalez.incitta.data.editorial.CookiePageMeta
import it.fabiodalez.incitta.data.editorial.CookieRow
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EmailText
import it.fabiodalez.incitta.data.editorial.EmailTextGroup
import it.fabiodalez.incitta.data.editorial.EmailTextVariable
import it.fabiodalez.incitta.data.editorial.EmailTexts
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.MailSettingsView
import it.fabiodalez.incitta.data.editorial.MailTestOutcome
import it.fabiodalez.incitta.data.editorial.MailTestResult
import it.fabiodalez.incitta.data.editorial.NewsletterBody
import it.fabiodalez.incitta.data.editorial.NewsletterLimits
import it.fabiodalez.incitta.data.editorial.NewsletterSettingsView
import it.fabiodalez.incitta.data.editorial.NewsletterText
import it.fabiodalez.incitta.data.editorial.RedirectBody
import it.fabiodalez.incitta.data.editorial.RedirectOptions
import it.fabiodalez.incitta.data.editorial.RedirectRow
import it.fabiodalez.incitta.data.editorial.SettingsMeta
import it.fabiodalez.incitta.data.editorial.SettingsResult
import it.fabiodalez.incitta.data.editorial.SettingsSource
import it.fabiodalez.incitta.data.editorial.TextPreview
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R13 con un server finto: le voci per ruolo, i testi con revisione ed esempio, posta, newsletter, cookie, indirizzi, cache. */
@OptIn(ExperimentalCoroutinesApi::class)
class SettingsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeSettings : SettingsSource {
        val calls = mutableListOf<String>()
        var failNext: Exception? = null
        var revision = "r1"
        var subject = EmailText(key = "notifications.moved.subject", label = "Oggetto", default = "Spostato: :title", value = "Spostato: :title",
            variables = listOf(EmailTextVariable("title", "titolo evento")), preview = "Spostato: Jazz", revision = "r1")
        var mailResult = MailTestResult(sent = true, title = "Messaggio spedito a ada@example.test")
        private fun maybeFail() { failNext?.let { failNext = null; throw it } }

        override suspend fun texts() = EmailTexts(listOf(EmailTextGroup("moved", "Evento spostato", listOf(subject.copy(revision = revision)))))
        override suspend fun previewText(key: String, value: String): TextPreview { calls += "preview:$key:$value"; return TextPreview(key, value.replace(":title", "Jazz")) }
        override suspend fun updateText(key: String, value: String, revision: String): SettingsResult<EmailText> {
            calls += "update:$key:$value:$revision"
            maybeFail()
            subject = subject.copy(value = value, modified = true, revision = "r2")
            this.revision = "r2"
            return SettingsResult(subject, SettingsMeta("Salvato: 1 testi riscritti, 0 tornati all'originale."))
        }
        override suspend fun resetTexts(): SettingsResult<EmailTexts> { calls += "reset"; return SettingsResult(texts(), SettingsMeta("Tutti i testi sono tornati come erano.")) }
        override suspend fun mail() = MailSettingsView(host = "smtp.example.test", hasPassword = true, recipient = "ada@example.test")
        override suspend fun testMail(): SettingsResult<MailTestOutcome> { calls += "mail-test"; maybeFail(); return SettingsResult(MailTestOutcome(mailResult, mail().copy(verified = mailResult.sent))) }
        override suspend fun newsletter() = NewsletterSettingsView(enabled = true, weekday = 5, time = "18:00", maxItems = 5,
            texts = mapOf("line" to NewsletterText("Scopri :count eventi", "Scopri :count eventi", 2000, listOf(EmailTextVariable("count", "quantità"))), "subject" to NewsletterText("Weekend", "Weekend", 200)),
            limits = NewsletterLimits(1, 30))
        override suspend fun saveNewsletter(body: NewsletterBody): SettingsResult<NewsletterSettingsView> {
            calls += "newsletter:${body.weekday}:${body.time}:${body.maxItems}:${body.line}:${body.heading}"
            return SettingsResult(newsletter().copy(weekday = body.weekday, time = body.time), SettingsMeta("Impostazioni newsletter salvate"))
        }
        override suspend fun scripts(params: List<Pair<String, String>>): EditorialPage<ConsentScriptRow> {
            calls += "scripts:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(listOf(ConsentScriptRow(1, "Statistiche")), PageMeta(total = 1))
        }
        override suspend fun script(id: Long) = ConsentScriptRow(id, "Statistiche", code = "window.x = 1;")
        var cookies = listOf(CookieRow(1, "necessary", name = "a", purpose = "a"), CookieRow(2, "necessary", name = "b", purpose = "b"), CookieRow(3, "necessary", name = "c", purpose = "c"))
        override suspend fun cookies(params: List<Pair<String, String>>): CookiePage {
            calls += "cookies:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return CookiePage(cookies, CookiePageMeta(total = cookies.size, categories = listOf(ConsentCategoryInfo("necessary", "Necessari"), ConsentCategoryInfo("marketing", "Marketing"))))
        }
        override suspend fun addCookie(body: CookieBody): SettingsResult<CookieRow> {
            calls += "add-cookie:${body.category}:${body.name}:${body.provider}"
            maybeFail()
            return SettingsResult(CookieRow(9, body.category, name = body.name, purpose = body.purpose), SettingsMeta("Cookie aggiunto."))
        }
        override suspend fun reorderCookies(category: String, ids: List<Long>): SettingsResult<List<CookieRow>> {
            calls += "order:$category:" + ids.joinToString(",")
            return SettingsResult(ids.map { id -> cookies.first { it.id == id } }, SettingsMeta("Ordine salvato."))
        }
        override suspend fun redirects(params: List<Pair<String, String>>): EditorialPage<RedirectRow> {
            calls += "redirects:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(listOf(RedirectRow(1, "/vecchio", "/nuovo")), PageMeta(total = 1))
        }
        override suspend fun redirectOptions() = RedirectOptions(defaultStatus = 301)
        override suspend fun addRedirect(body: RedirectBody): SettingsResult<RedirectRow> {
            calls += "add-redirect:${body.fromPath}:${body.toPath}:${body.cityId}:${body.status}:${body.isWildcard}"
            return SettingsResult(RedirectRow(5, body.fromPath, body.toPath))
        }
        override suspend fun cache() = CacheSettingsView(store = "frontend-file", ttlMinutes = 60)
        var redisOk = true
        override suspend fun clearCache(): SettingsResult<CacheCleared> { calls += "clear"; return SettingsResult(CacheCleared(true), SettingsMeta("Cache del sito pulite.")) }
        override suspend fun verifyRedis(): SettingsResult<CacheVerified> { calls += "verify"; return SettingsResult(CacheVerified(redisOk)) }
    }

    private val desk = FakeDesk()
    private val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))
    private val source = FakeSettings()

    private fun conflict() = ApiException(422, ApiProblem(message = "x", code = "VALIDATION_FAILED", fields = mapOf("revision" to listOf("Cambiato nel frattempo."))))

    @Test fun theSectionsFollowThePanelPermissions() {
        val moderator = EditorialPermissions(mapOf("system_manage" to false, "mail_manage" to false, "pages_manage" to false))
        val admin = EditorialPermissions(mapOf("system_manage" to true, "mail_manage" to false, "pages_manage" to true))
        val superAdmin = EditorialPermissions(mapOf("system_manage" to true, "mail_manage" to true, "pages_manage" to true))
        assertEquals(emptyList<SettingsSection>(), settingsSections(moderator))
        assertEquals(listOf(SettingsSection.Texts, SettingsSection.Scripts, SettingsSection.Cookies, SettingsSection.Redirects, SettingsSection.Cache), settingsSections(admin))
        assertEquals(SettingsSection.entries, settingsSections(superAdmin))
        assertTrue(SettingsSection.entries.all { it.webPath.startsWith("admin/") })
    }

    @Test fun anEmailTextIsSavedWithItsRevisionAndTheExampleComesFromTheServer() = runTest(dispatcher) {
        val viewModel = EmailTextEditViewModel(repository, source, SavedStateHandle(mapOf("key" to "notifications.moved.subject")))
        val loaded = viewModel.uiState.value
        assertEquals("Spostato: :title", loaded.value)
        assertEquals("Spostato: Jazz", loaded.preview)
        assertFalse(loaded.dirty)

        viewModel.insert(EmailTextVariable("title"))
        assertEquals("Spostato: :title :title", viewModel.uiState.value.value)
        viewModel.setValue("Nuovo orario per :title")
        advanceTimeBy(EmailTextEditViewModel.PREVIEW_PAUSE_MILLIS + 10)
        assertEquals("Nuovo orario per Jazz", viewModel.uiState.value.preview)
        /* La pausa: le battute di prima non hanno chiesto niente. */
        assertEquals(listOf("preview:notifications.moved.subject:Nuovo orario per :title"), source.calls.filter { it.startsWith("preview") })

        viewModel.save()
        advanceUntilIdle()
        assertTrue("update:notifications.moved.subject:Nuovo orario per :title:r1" in source.calls)
        assertTrue(viewModel.uiState.value.changed)
        assertEquals(UiText.Raw("Salvato: 1 testi riscritti, 0 tornati all'originale."), viewModel.uiState.value.message)
    }

    @Test fun anUnknownPlaceholderBlocksSavingAndAConflictReloadsKeepingTheDraft() = runTest(dispatcher) {
        val viewModel = EmailTextEditViewModel(repository, source, SavedStateHandle(mapOf("key" to "notifications.moved.subject")))
        viewModel.setValue("Spostato :inventato")
        assertEquals(listOf("inventato"), viewModel.uiState.value.unknown)
        assertFalse(viewModel.uiState.value.canSave)
        viewModel.save()
        assertTrue(source.calls.none { it.startsWith("update") })

        viewModel.setValue("Spostato davvero :title")
        source.failNext = conflict()
        source.revision = "r9"
        viewModel.save()
        advanceUntilIdle()
        val after = viewModel.uiState.value
        assertEquals(UiText.Res(it.fabiodalez.incitta.R.string.config_texts_changed), after.message)
        assertEquals("Spostato davvero :title", after.value)
        assertEquals("r9", (after.text as Loadable.Ready).value.revision)
        assertFalse(after.changed)
    }

    @Test fun anUnknownKeyIsNotFound() = runTest(dispatcher) {
        val viewModel = EmailTextEditViewModel(repository, source, SavedStateHandle(mapOf("key" to "notifications.weekend.subject")))
        assertEquals(Loadable.Empty, viewModel.uiState.value.text)
    }

    @Test fun theTextsListResetsEverythingAfterConfirming() = runTest(dispatcher) {
        val viewModel = EmailTextsViewModel(repository, source, SavedStateHandle())
        assertEquals("moved", viewModel.uiState.value.group)
        viewModel.askReset()
        assertTrue(viewModel.uiState.value.confirmingReset)
        viewModel.reset()
        assertEquals(listOf("reset"), source.calls)
        assertFalse(viewModel.uiState.value.confirmingReset)
        assertEquals(UiText.Raw("Tutti i testi sono tornati come erano."), viewModel.uiState.value.message)
    }

    @Test fun theMailTestUsesTheSavedConfigurationAndShowsTheServerAnswer() = runTest(dispatcher) {
        val viewModel = MailSettingsViewModel(repository, source)
        viewModel.askTest()
        viewModel.sendTest()
        assertEquals(listOf("mail-test"), source.calls)
        assertEquals(true, viewModel.uiState.value.result?.sent)
        assertTrue((viewModel.uiState.value.mail as Loadable.Ready).value.verified)

        source.mailResult = MailTestResult(sent = false, title = "Il server ha rifiutato il messaggio", detail = "Connection refused")
        viewModel.sendTest()
        assertEquals("Connection refused", viewModel.uiState.value.result?.detail)
        assertFalse(viewModel.uiState.value.confirmingTest)
    }

    @Test fun theNewsletterFollowsThePanelRules() = runTest(dispatcher) {
        val viewModel = NewsletterViewModel(repository, source)
        assertTrue(viewModel.uiState.value.canSave)
        viewModel.edit { it.copy(time = "25:00", maxItems = "0") }
        assertEquals(setOf("time", "max_items"), viewModel.uiState.value.problems)
        viewModel.save()
        assertTrue(source.calls.isEmpty())
        viewModel.edit { it.copy(time = "18:45", maxItems = "3", weekday = 6) }
        viewModel.setText("line", "Variabile :inesistente")
        assertEquals(setOf("line"), viewModel.uiState.value.problems)
        viewModel.setText("line", "")
        viewModel.insert("line", EmailTextVariable("count"))
        assertEquals(" :count", viewModel.uiState.value.draft.text("line"))
        viewModel.save()
        /* Il titolo vuoto parte come `null`: sul server torna all'originale. */
        assertEquals(listOf("newsletter:6:18:45:3::count:null"), source.calls)
        assertEquals(UiText.Raw("Impostazioni newsletter salvate"), viewModel.uiState.value.message)
    }

    @Test fun cookiesStartOnTheFirstPurposeAndTheFormKeepsTheOpenTab() = runTest(dispatcher) {
        val viewModel = CookiesViewModel(repository, source, SavedStateHandle())
        assertEquals("cookies:page=1&per_page=50&tab=necessary", source.calls.first())
        assertEquals(listOf("necessary", "marketing"), viewModel.uiState.value.categories.map { it.value })
        viewModel.selectTab("marketing")
        viewModel.openAdd()
        assertEquals("marketing", viewModel.uiState.value.draft?.category)
        viewModel.add()
        assertTrue(source.calls.none { it.startsWith("add-cookie") })
        viewModel.editDraft { it.copy(name = "_ads", purpose = "Annunci", provider = "  ") }
        viewModel.add()
        assertTrue("add-cookie:marketing:_ads:null" in source.calls)
        assertNull(viewModel.uiState.value.draft)
    }

    @Test fun cookiesMoveOneStepAndSendTheWholeTab() = runTest(dispatcher) {
        val viewModel = CookiesViewModel(repository, source, SavedStateHandle())
        assertTrue(cookiesReorderable(viewModel.list.current.value))
        viewModel.move(source.cookies[2], -1)
        assertTrue("order:necessary:1,3,2" in source.calls)
        assertEquals(UiText.Raw("Ordine salvato."), viewModel.uiState.value.message)
        /* Con una ricerca non si riordina. */
        viewModel.search("a")
        assertFalse(cookiesReorderable(viewModel.list.current.value))
    }

    @Test fun aRedirectNeedsAnInternalTarget() = runTest(dispatcher) {
        val viewModel = RedirectNewViewModel(repository, source)
        viewModel.edit { it.copy(fromPath = "/volantino", toPath = "//evil.example") }
        assertFalse(viewModel.uiState.value.draft.valid)
        viewModel.save()
        assertTrue(source.calls.isEmpty())
        viewModel.edit { it.copy(toPath = "/eventi", status = 302, wildcard = true) }
        viewModel.save()
        assertEquals(listOf("add-redirect:/volantino:/eventi:null:302:true"), source.calls)
        assertEquals(5L, viewModel.uiState.value.created?.id)
    }

    @Test fun redirectsKeepTheSortOfThePanelColumns() = runTest(dispatcher) {
        val saved = SavedStateHandle()
        val viewModel = RedirectsViewModel(repository, source, saved)
        assertEquals("redirects:page=1&per_page=20", source.calls.first())
        viewModel.sortBy(RedirectsViewModel.SORT_HITS_MOST)
        assertEquals("redirects:page=1&per_page=20&sort=-hits", source.calls.last())
        assertEquals("-hits", saved.get<String>("sort"))
    }

    @Test fun theCacheIsClearedAfterConfirmingAndRedisIsVerified() = runTest(dispatcher) {
        val viewModel = CacheViewModel(repository, source)
        viewModel.askClear()
        viewModel.clear()
        assertEquals(listOf("clear"), source.calls)
        assertEquals(UiText.Raw("Cache del sito pulite."), viewModel.uiState.value.message)
        source.redisOk = false
        viewModel.verify()
        assertEquals(false, viewModel.uiState.value.redisOk)
        assertEquals(UiText.Res(it.fabiodalez.incitta.R.string.config_cache_redis_failed), viewModel.uiState.value.message)
    }

    @Test fun scriptsAreReadOnly() = runTest(dispatcher) {
        val list = ScriptsViewModel(repository, source, SavedStateHandle())
        assertEquals("scripts:page=1&per_page=20", source.calls.first())
        assertEquals(1, list.list.current.value.items.size)
        val detail = ScriptDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 1L)))
        assertEquals("window.x = 1;", (detail.uiState.value as Loadable.Ready).value.code)
    }
}
