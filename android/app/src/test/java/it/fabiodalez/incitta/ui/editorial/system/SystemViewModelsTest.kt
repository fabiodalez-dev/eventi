package it.fabiodalez.incitta.ui.editorial.system

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.DeletedRow
import it.fabiodalez.incitta.data.editorial.EditorialImportSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.ImportFormDefaults
import it.fabiodalez.incitta.data.editorial.ImportFormLimits
import it.fabiodalez.incitta.data.editorial.ImportPreview
import it.fabiodalez.incitta.data.editorial.ImportRunQueued
import it.fabiodalez.incitta.data.editorial.ImportRunRow
import it.fabiodalez.incitta.data.editorial.ImportSourceFormOptions
import it.fabiodalez.incitta.data.editorial.ImportTypeChoice
import it.fabiodalez.incitta.data.editorial.ImportVenueChoice
import it.fabiodalez.incitta.data.editorial.MappingDraft
import it.fabiodalez.incitta.data.editorial.MappingEntry
import it.fabiodalez.incitta.data.editorial.ModerationMeta
import it.fabiodalez.incitta.data.editorial.ModerationResult
import it.fabiodalez.incitta.data.editorial.ScheduledNotificationRow
import it.fabiodalez.incitta.data.editorial.SchedulerOverviewData
import it.fabiodalez.incitta.data.editorial.SystemRef
import it.fabiodalez.incitta.data.editorial.SystemSource
import it.fabiodalez.incitta.ui.editorial.allKeys
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R12 con un server finto: le voci per ruolo, gli elenchi con i filtri, il modulo con le regole del pannello, le azioni con la password. */
@OptIn(ExperimentalCoroutinesApi::class)
class SystemViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private class FakeSystem : SystemSource {
        val calls = mutableListOf<String>()
        val bodies = mutableListOf<JsonObject>()
        var sources = listOf(EditorialImportSource(id = 3, type = "ics", url = "https://x.test/a.ics", city = SystemRef(1, "Padova"), defaultCategory = SystemRef(2, "Musica"), isActive = true))
        var notifications = listOf(ScheduledNotificationRow(id = 9, status = "pending", typeLabel = "Promemoria"))
        var failNext: Exception? = null

        private fun maybeFail() { failNext?.let { failNext = null; throw it } }
        private fun <T> page(rows: List<T>) = EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))
        private fun params(params: List<Pair<String, String>>) = params.joinToString("&") { "${it.first}=${it.second}" }

        override suspend fun importSources(params: List<Pair<String, String>>): EditorialPage<EditorialImportSource> { calls += "sources:" + params(params); return page(sources) }
        override suspend fun importSource(id: Long): EditorialImportSource { calls += "source:$id"; maybeFail(); return sources.first { it.id == id } }
        override suspend fun importForm(): ImportSourceFormOptions {
            calls += "form"
            return ImportSourceFormOptions(types = listOf(ImportTypeChoice("ics", "ICS", true), ImportTypeChoice("manual", "Manuale", false)),
                categories = listOf(SystemRef(2, "Musica")), defaults = ImportFormDefaults(cityId = 1, type = "ics", isActive = true))
        }
        override suspend fun importVenues(query: String): List<ImportVenueChoice> { calls += "venues:$query"; return listOf(ImportVenueChoice(5, "Teatro Verdi", "Padova")) }
        override suspend fun createImportSource(body: JsonObject): ModerationResult<EditorialImportSource> {
            calls += "create"; bodies += body; maybeFail()
            return ModerationResult(EditorialImportSource(id = 11, type = "ics"), ModerationMeta("Sorgente salvata."))
        }
        override suspend fun updateImportSource(id: Long, body: JsonObject): ModerationResult<EditorialImportSource> {
            calls += "update:$id"; bodies += body; maybeFail()
            val active = (body["is_active"] as? JsonPrimitive)?.content?.toBooleanStrictOrNull()
            return ModerationResult(sources.first { it.id == id }.let { if (active != null) it.copy(isActive = active) else it })
        }
        override suspend fun deleteImportSource(id: Long): ModerationResult<DeletedRow> { calls += "delete:$id"; maybeFail(); return ModerationResult(DeletedRow(id, true)) }
        override suspend fun previewImport(id: Long): ImportPreview { calls += "preview:$id"; return ImportPreview(error = "Non è un calendario.") }
        override suspend fun runImport(id: Long): ModerationResult<ImportRunQueued> { calls += "run:$id"; return ModerationResult(ImportRunQueued(id, true), ModerationMeta("Import accodato.")) }
        override suspend fun importRuns(id: Long, params: List<Pair<String, String>>): EditorialPage<ImportRunRow> { calls += "runs:$id:" + params(params); return page(listOf(ImportRunRow(id = 1))) }
        override suspend fun scheduledNotifications(params: List<Pair<String, String>>): EditorialPage<ScheduledNotificationRow> { calls += "notifications:" + params(params); return page(notifications) }
        override suspend fun cancelNotification(id: Long): ModerationResult<ScheduledNotificationRow> {
            calls += "cancel:$id"; maybeFail()
            return ModerationResult(notifications.first { it.id == id }.copy(status = "cancelled", abilities = null), ModerationMeta("Invio annullato."))
        }
        override suspend fun scheduledOperations(): SchedulerOverviewData { calls += "operations"; return SchedulerOverviewData(queue = "database") }
    }

    private val desk = FakeDesk()
    private val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))

    private fun passwordRequired() = ApiException(423, ApiProblem(message = "Password", code = "PASSWORD_REQUIRED"))

    @Test fun theModeratorSeesOnlyTheScheduledNotificationsLikeInThePanel() {
        val moderator = EditorialPermissions(mapOf("notifications_view" to true))
        val admin = EditorialPermissions(allKeys.associateWith { it != "mail_manage" })
        assertEquals(listOf(SystemSection.Notifications), systemSections(moderator))
        assertEquals(SystemSection.entries, systemSections(admin))
        assertEquals(emptyList<SystemSection>(), systemSections(EditorialPermissions()))
        assertEquals("scheduled_notifications_due", SystemSection.Notifications.badge)
    }

    @Test fun theImportListLoadsWithoutSearchAndTheFailedTileFilterOnce() {
        val source = FakeSystem()
        /* Gli argomenti di `SystemImportsRoute(filter = "failed:1")`, la rotta di «Import falliti» del riepilogo. */
        val saved = SavedStateHandle(mapOf("tab" to "", "filter" to "failed:1", "q" to "ignorata"))
        val viewModel = ImportSourcesViewModel(repository, source, saved)
        assertEquals("sources:page=1&per_page=20&filter[failed]=1", source.calls.first())
        assertEquals(listOf(3L), viewModel.list.current.value.items.map { it.id })
        viewModel.applyFilters(mapOf("active" to "0", "type" to "ics"))
        viewModel.sortBy(ImportSourcesViewModel.SORT_OLDEST)
        assertEquals("sources:page=1&per_page=20&filter[active]=0&filter[type]=ics&sort=last_run_at", source.calls.last())
        /* Il sistema riapre l'app: restano i criteri scelti. */
        ImportSourcesViewModel(repository, source, saved)
        assertEquals("sources:page=1&per_page=20&filter[active]=0&filter[type]=ics&sort=last_run_at", source.calls.last())
    }

    @Test fun theFormFollowsThePanelRules() {
        val limits = ImportFormLimits(maxUrlLength = 20)
        val ok = ImportDraft(cityId = 1, type = "ics", categoryId = 2, url = "https://x.test/a")
        assertEquals(emptyMap<String, Int>(), importDraftProblems(ok, limits))
        /* Senza locale la categoria è obbligatoria; con un locale no. */
        assertEquals(setOf(ImportField.CATEGORY), importDraftProblems(ok.copy(categoryId = null), limits).keys)
        assertEquals(emptyMap<String, Int>(), importDraftProblems(ok.copy(categoryId = null, venue = SystemRef(5, "Teatro")), limits))
        assertEquals(R.string.system_import_error_city, importDraftProblems(ok.copy(cityId = null), limits)[ImportField.CITY])
        assertEquals(setOf(ImportField.URL), importDraftProblems(ok.copy(url = "https://x.test/" + "a".repeat(20)), limits).keys)
        /* Due chiavi uguali nella mappatura non vanno. */
        val twice = MappingDraft(listOf(MappingEntry("timezone", "Europe/Rome"), MappingEntry(" timezone ", "UTC")))
        assertEquals(setOf(ImportField.MAPPING), importDraftProblems(ok.copy(mapping = twice), limits).keys)
    }

    @Test fun aNewSourceSendsEveryFieldAnEditOnlyTheChangedOnes() {
        val draft = ImportDraft(cityId = 1, type = "ics", categoryId = 2, url = " https://x.test/a.ics ", mapping = MappingDraft(listOf(MappingEntry("timezone", "Europe/Rome"))))
        val created = importDraftBody(draft, null)
        assertEquals(setOf("city_id", "venue_id", "type", "default_category_id", "url", "mapping", "is_active"), created.keys)
        assertEquals(JsonNull, created["venue_id"])
        assertEquals(JsonPrimitive("https://x.test/a.ics"), created["url"])
        val original = EditorialImportSource(id = 3, type = "ics", url = "upload:calendario.csv", city = SystemRef(1, "Padova"), defaultCategory = SystemRef(2, "Musica"), venue = SystemRef(5, "Teatro"), isActive = true)
        val untouched = ImportDraft.of(original)
        assertTrue(importDraftBody(untouched, original).isEmpty())
        /* Togliere il locale manda un `null` esplicito; l'indirizzo che non cambia non parte (un file caricato non si riverifica). */
        val edited = importDraftBody(untouched.copy(venue = null, active = false), original)
        assertEquals(setOf("venue_id", "is_active"), edited.keys)
        assertEquals(JsonNull, edited["venue_id"])
    }

    @Test fun aMaskedAddressIsReadOnlyAndNeverSentBack() {
        val masked = kotlinx.serialization.json.Json { ignoreUnknownKeys = true }.decodeFromString(
            EditorialImportSource.serializer(),
            """{"id":4,"type":"ics","url":"https://calendar.google.com/…","url_masked":true,"has_credentials":false,"is_active":true}""",
        )
        assertTrue(masked.urlMasked)
        assertFalse(importUrlEditable(masked))
        assertTrue(importUrlEditable(null))
        assertTrue(importUrlEditable(masked.copy(urlMasked = false)))
        /* Il modulo apre con l'indirizzo mascherato: cambiando altro, l'indirizzo non parte. */
        val body = importDraftBody(ImportDraft.of(masked).copy(active = false), masked)
        assertEquals(setOf("is_active"), body.keys)
    }

    @Test fun theFormCreatesASourceWithTheDefaultsOfThePanel() {
        val source = FakeSystem()
        val viewModel = ImportSourceFormViewModel(repository, source, SavedStateHandle(mapOf("id" to 0L)))
        val loaded = viewModel.uiState.value
        assertTrue(loaded.options is Loadable.Ready)
        assertEquals(ImportDraft(cityId = 1, type = "ics", active = true), loaded.draft)
        /* Senza locale né categoria non si salva. */
        assertFalse(loaded.canSave)
        viewModel.searchVenues("Verdi")
        assertEquals("venues:Verdi", source.calls.last())
        viewModel.chooseVenue(viewModel.uiState.value.venueResults.single())
        assertEquals(SystemRef(5, "Teatro Verdi"), viewModel.uiState.value.draft.venue)
        viewModel.setUrl("https://x.test/a.ics")
        assertTrue(viewModel.uiState.value.canSave)
        viewModel.save()
        assertEquals("create", source.calls.last())
        assertEquals(JsonPrimitive(5), source.bodies.last()["venue_id"])
        assertEquals(11L, viewModel.uiState.value.saved?.id)
    }

    @Test fun theServerErrorsGoUnderTheirFields() {
        val source = FakeSystem()
        val viewModel = ImportSourceFormViewModel(repository, source, SavedStateHandle(mapOf("id" to 3L)))
        source.failNext = ApiException(422, ApiProblem(message = "x", code = "VALIDATION_FAILED", fields = mapOf("url" to listOf("Indirizzo della rete interna."), "credentials" to listOf("Solo dal sito."))))
        viewModel.setUrl("http://127.0.0.1/a.ics")
        viewModel.save()
        assertEquals("update:3", source.calls.last())
        assertEquals(setOf("url"), source.bodies.last().keys)
        val errors = viewModel.uiState.value.errors
        assertEquals("Indirizzo della rete interna.", errors.field("url"))
        assertEquals("Solo dal sito.", errors.field("credentials"))
        assertNull(viewModel.uiState.value.saved)
    }

    @Test fun theDetailTogglesRunsAndDeletesWithThePassword() {
        val source = FakeSystem().apply { sources = listOf(sources.single().copy(abilities = abilities("update", "destroy", "run"))) }
        val viewModel = ImportSourceDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 3L)))
        assertTrue(viewModel.uiState.value.source is Loadable.Ready)
        viewModel.toggleActive()
        assertEquals(JsonObject(mapOf("is_active" to JsonPrimitive(false))), source.bodies.last())
        assertFalse((viewModel.uiState.value.source as Loadable.Ready).value.isActive)
        assertEquals(UiText.Res(R.string.system_import_deactivated), viewModel.uiState.value.message)
        viewModel.askRun()
        viewModel.confirm()
        assertEquals("run:3", source.calls.last())
        assertEquals(UiText.Raw("Import accodato."), viewModel.uiState.value.message)
        assertNull(viewModel.uiState.value.confirm)
        /* Eliminare chiede la password recente: si sblocca e la chiamata riparte. */
        source.failNext = passwordRequired()
        viewModel.askDelete()
        viewModel.confirm()
        assertEquals(EditorialLockReason.PasswordRequired, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertEquals(listOf("delete:3", "delete:3"), source.calls.filter { it.startsWith("delete") })
        assertTrue(viewModel.uiState.value.deleted)
        assertTrue(viewModel.uiState.value.changed)
    }

    @Test fun closingThePasswordPromptKeepsTheSourceWithTheError() {
        val source = FakeSystem()
        val viewModel = ImportSourceDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 3L)))
        source.failNext = passwordRequired()
        viewModel.askDelete()
        viewModel.confirm()
        repository.guard.dismiss()
        val state = viewModel.uiState.value
        assertFalse(state.deleted)
        assertEquals(ImportConfirm.Delete, state.confirm)
        assertNotNull(state.confirmError)
    }

    @Test fun thePreviewShowsTheReasonWhenTheCalendarCannotBeRead() {
        val source = FakeSystem()
        val viewModel = ImportPreviewViewModel(repository, source, SavedStateHandle(mapOf("id" to 3L)))
        assertEquals("preview:3", source.calls.last())
        assertEquals("Non è un calendario.", (viewModel.uiState.value as Loadable.Ready).value.error)
    }

    @Test fun theRunsKeepTheirFilterAndSort() {
        val source = FakeSystem()
        val saved = SavedStateHandle(mapOf("id" to 3L))
        val viewModel = ImportRunsViewModel(repository, source, saved)
        assertEquals("runs:3:page=1&per_page=20", source.calls.last())
        viewModel.applyFilters(mapOf("status" to "failed"))
        viewModel.sortBy(ImportRunsViewModel.SORT_OLDEST)
        assertEquals("runs:3:page=1&per_page=20&filter[status]=failed&sort=created_at", source.calls.last())
        ImportRunsViewModel(repository, source, saved)
        assertEquals("runs:3:page=1&per_page=20&filter[status]=failed&sort=created_at", source.calls.last())
    }

    @Test fun aScheduledNotificationIsCancelledAndItsRowStays() {
        val source = FakeSystem().apply { notifications = listOf(ScheduledNotificationRow(id = 9, status = "pending", abilities = abilities("cancel"))) }
        val viewModel = ScheduledNotificationsViewModel(repository, source, SavedStateHandle(mapOf("filter" to "due:1")))
        assertEquals("notifications:page=1&per_page=20&filter[due]=1", source.calls.first())
        viewModel.search("anna@")
        viewModel.sortBy(ScheduledNotificationsViewModel.SORT_EARLIEST)
        assertEquals("notifications:page=1&per_page=20&q=anna@&filter[due]=1&sort=send_at", source.calls.last())
        viewModel.askCancel(viewModel.list.current.value.items.single())
        viewModel.cancel()
        assertEquals("cancel:9", source.calls.last())
        val row = viewModel.list.current.value.items.single()
        assertEquals("cancelled", row.status)
        assertNull(viewModel.uiState.value.confirming)
        assertEquals(UiText.Raw("Invio annullato."), viewModel.uiState.value.message)
    }

    @Test fun theOperationsPageLoadsOnce() {
        val source = FakeSystem()
        val viewModel = ScheduledOperationsViewModel(repository, source)
        assertEquals(listOf("operations"), source.calls)
        assertEquals("database", (viewModel.uiState.value as Loadable.Ready).value.queue)
    }
}
